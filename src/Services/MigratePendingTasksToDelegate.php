<?php

/**
 * This file is part of the dbflow-labs/core package.
 *
 * Copyright (c) 2026 Baron Wang <hello@dbflow.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 * @link    https://dbflow.dev
 * @see     https://github.com/dbflow-labs/dbflow-core
 */

declare(strict_types=1);

namespace DbflowLabs\Core\Services;

use DbflowLabs\Core\Actions\ReassignTask;
use DbflowLabs\Core\Enums\DelegationLifecycle;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\PendingTasksMigrationCompleted;
use DbflowLabs\Core\Exceptions\InvalidDelegationException;
use DbflowLabs\Core\Models\WorkflowDelegation;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use Throwable;

final class MigratePendingTasksToDelegate
{
    use ResolvesActorUserId;

    public function __construct(
        private readonly ReassignTask $reassignTask,
    ) {}

    /**
     * Explicitly migrate matching pending assignments for a delegation via ReassignTask.
     * Not invoked automatically when a delegation is created.
     *
     * @return array{
     *     matched: int,
     *     migrated: int,
     *     skipped: int,
     *     failed: int,
     *     dry_run: bool,
     *     task_ids: list<int|string>,
     *     migrated_task_ids: list<int|string>,
     *     skipped_task_ids: list<int|string>,
     *     failed_task_ids: list<int|string>,
     *     failures: list<array{task_id: int|string, reason: string}>,
     *     skip_reasons: list<array{task_id: int|string, reason: string}>
     * }
     */
    public function handle(
        WorkflowDelegation $delegation,
        mixed $actor = null,
        ?int $maxTasks = null,
        ?string $batchKey = null,
        bool $dryRun = false,
    ): array {
        $actorId = $this->resolveActorUserId($actor ?? $delegation->created_by_user_id ?? $delegation->delegator_user_id);

        if ($actorId === null) {
            throw new InvalidDelegationException('Migration actor user id is invalid.');
        }

        $actorId = (string) $actorId;

        if ($delegation->revoked_at !== null) {
            throw new InvalidDelegationException('Cannot migrate pending tasks for a revoked delegation.');
        }

        if ($delegation->lifecycle() !== DelegationLifecycle::Active) {
            throw new InvalidDelegationException(
                'Only active delegations can migrate pending tasks.',
            );
        }

        $limit = max(1, $maxTasks ?? (int) config('dbflow.delegation.migration_batch_size', 100));
        $batchKey = $batchKey !== null && trim($batchKey) !== '' ? trim($batchKey) : null;

        $matchedTaskIds = [];
        $migratedTaskIds = [];
        $skipped = [];
        $failures = [];
        $attempted = 0;

        $assignments = WorkflowTaskAssignment::query()
            ->where('assignee_user_id', $delegation->delegator_user_id)
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->whereHas('workflowTask', static function ($taskQuery): void {
                $taskQuery->where('status', WorkflowTaskStatus::Pending);
            })
            ->with(['workflowTask.workflowInstance.workflow'])
            ->orderBy('id')
            ->get();

        /** @var WorkflowTaskAssignment $assignment */
        foreach ($assignments as $assignment) {
            $task = $assignment->workflowTask;

            if (! $task instanceof WorkflowTask) {
                $skipped[] = ['task_id' => $assignment->workflow_task_id, 'reason' => 'missing_task'];

                continue;
            }

            $workflowKey = $task->workflowInstance?->workflow?->key;

            if (! $delegation->matchesScope($workflowKey, $task->node_key)) {
                $skipped[] = ['task_id' => $task->getKey(), 'reason' => 'scope_mismatch'];

                continue;
            }

            if ($assignment->previous_assignment_id !== null) {
                $skipped[] = ['task_id' => $task->getKey(), 'reason' => 'already_explicitly_reassigned'];

                continue;
            }

            if ($assignment->effectiveAssigneeUserId() === (string) $delegation->delegate_user_id) {
                $skipped[] = ['task_id' => $task->getKey(), 'reason' => 'already_effective_delegate'];

                continue;
            }

            $matchedTaskIds[] = $task->getKey();

            if ($attempted >= $limit) {
                $skipped[] = ['task_id' => $task->getKey(), 'reason' => 'batch_limit'];

                continue;
            }

            $attempted++;

            if ($dryRun) {
                $migratedTaskIds[] = $task->getKey();

                continue;
            }

            $operationKey = $batchKey !== null
                ? $batchKey.':task:'.$task->getKey().':assignment:'.$assignment->getKey()
                : null;

            try {
                $this->reassignTask->handle(
                    $task,
                    $delegation->delegator_user_id,
                    (string) $delegation->delegate_user_id,
                    $delegation->reason,
                    $operationKey,
                    $assignment->getKey(),
                );
                $migratedTaskIds[] = $task->getKey();
            } catch (Throwable $exception) {
                $failures[] = [
                    'task_id' => $task->getKey(),
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        $summary = [
            'matched' => count($matchedTaskIds),
            'migrated' => count($migratedTaskIds),
            'skipped' => count($skipped),
            'failed' => count($failures),
            'dry_run' => $dryRun,
            'task_ids' => $matchedTaskIds,
            'migrated_task_ids' => $migratedTaskIds,
            'skipped_task_ids' => array_values(array_map(
                static fn (array $row): int|string => $row['task_id'],
                $skipped,
            )),
            'failed_task_ids' => array_values(array_map(
                static fn (array $row): int|string => $row['task_id'],
                $failures,
            )),
            'failures' => $failures,
            'skip_reasons' => $skipped,
        ];

        if (! $dryRun) {
            event(new PendingTasksMigrationCompleted($delegation, $summary, $actorId));
        }

        return $summary;
    }
}
