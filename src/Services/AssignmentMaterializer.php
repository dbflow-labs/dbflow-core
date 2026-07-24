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

use DbflowLabs\Core\Delegation\ResolveEffectiveAssignee;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\AssignmentSource;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Events\TaskAssignedViaDelegation;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use Illuminate\Support\Carbon;

final class AssignmentMaterializer
{
    public function __construct(
        private readonly ResolveEffectiveAssignee $effectiveAssigneeResolver,
        private readonly WorkflowLogger $logger,
    ) {}

    /**
     * @param  list<int|string>  $assigneeUserIds
     * @return list<WorkflowTaskAssignment>
     */
    public function createAssignments(
        WorkflowTask $task,
        WorkflowInstance $instance,
        ApprovalMode $approvalMode,
        array $assigneeUserIds,
        ?string $workflowKey = null,
        ?string $nodeKey = null,
        bool $delegationEnabled = false,
    ): array {
        $sequence = 1;
        $created = [];
        $now = Carbon::now('UTC');

        foreach ($assigneeUserIds as $assigneeUserId) {
            $originalId = (string) $assigneeUserId;
            $resolution = $this->effectiveAssigneeResolver->resolve(
                $originalId,
                $workflowKey,
                $nodeKey,
                $now,
                $delegationEnabled,
            );

            $assignment = WorkflowTaskAssignment::query()->create([
                'workflow_task_id' => $task->getKey(),
                'assignee_user_id' => $originalId,
                'original_assignee_user_id' => $resolution->originalUserId(),
                'effective_assignee_user_id' => $resolution->effectiveUserId(),
                'assignment_source' => $resolution->source()->value,
                'delegation_id' => $resolution->delegationId(),
                'status' => WorkflowTaskAssignmentStatus::Pending,
                'sequence' => $approvalMode->isSequential() ? $sequence++ : null,
            ]);

            $created[] = $assignment;

            if ($resolution->source() === AssignmentSource::Delegation && $resolution->delegationId() !== null) {
                $this->logger->log(
                    $instance,
                    WorkflowLogEvent::TaskAssignedViaDelegation,
                    $task,
                    null,
                    payload: [
                        'node_key' => $nodeKey,
                        'original_assignee_user_id' => $resolution->originalUserId(),
                        'effective_assignee_user_id' => $resolution->effectiveUserId(),
                        'delegation_id' => $resolution->delegationId(),
                        'assignment_id' => $assignment->getKey(),
                        'scope' => $resolution->delegation()?->scopeType()->value,
                    ],
                );

                event(new TaskAssignedViaDelegation(
                    $task,
                    $instance,
                    $assignment,
                    $resolution->originalUserId(),
                    $resolution->effectiveUserId(),
                    $resolution->delegationId(),
                ));
            }
        }

        return $created;
    }
}
