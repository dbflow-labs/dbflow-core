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

namespace DbflowLabs\Core\Actions;

use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\AssignmentSource;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskReassigned;
use DbflowLabs\Core\Exceptions\IdempotencyConflictException;
use DbflowLabs\Core\Exceptions\TaskNotPendingException;
use DbflowLabs\Core\Exceptions\UserCannotReassignTaskException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\TaskHooksRegistry;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use DbflowLabs\Core\Support\ResolvesTaskHooks;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReassignTask
{
    use ResolvesActorUserId;
    use ResolvesTaskHooks;

    public function __construct(
        private readonly WorkflowLogger $logger,
        private readonly ?TaskHooksRegistry $taskHooksRegistry = null,
    ) {}

    public function handle(
        WorkflowTask $task,
        mixed $fromActor,
        string $toUserId,
        ?string $comment = null,
        ?string $idempotencyKey = null,
        int|string|null $assignmentId = null,
    ): WorkflowInstance {
        return $this->transfer(
            $task,
            $fromActor,
            $toUserId,
            $comment,
            $idempotencyKey,
            $assignmentId,
            AssignmentSource::Reassignment,
            requireActor: true,
            allowSameTargetNoOp: false,
        );
    }

    public function handleEscalation(
        WorkflowTask $task,
        string $toUserId,
        ?string $idempotencyKey = null,
        ?string $comment = null,
    ): WorkflowInstance {
        return $this->transfer(
            $task,
            null,
            $toUserId,
            $comment,
            $idempotencyKey,
            null,
            AssignmentSource::Escalation,
            requireActor: false,
            allowSameTargetNoOp: true,
        );
    }

    private function transfer(
        WorkflowTask $task,
        mixed $fromActor,
        string $toUserId,
        ?string $comment,
        ?string $idempotencyKey,
        int|string|null $assignmentId,
        AssignmentSource $assignmentSource,
        bool $requireActor,
        bool $allowSameTargetNoOp,
    ): WorkflowInstance {
        $toUserId = trim($toUserId);

        if ($toUserId === '') {
            throw new UserCannotReassignTaskException('Target assignee user id is required.');
        }

        $operationKey = $this->normalizeOperationKey($idempotencyKey);

        if ($assignmentSource === AssignmentSource::Reassignment
            && (bool) config('dbflow.reassignment.require_reason', false)
            && ($comment === null || trim($comment) === '')) {
            throw new UserCannotReassignTaskException('Reassignment reason is required.');
        }

        return DB::transaction(function () use ($task, $fromActor, $toUserId, $comment, $operationKey, $assignmentId, $assignmentSource, $requireActor, $allowSameTargetNoOp): WorkflowInstance {
            /** @var WorkflowTask $lockedTask */
            $lockedTask = WorkflowTask::query()
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->whereKey($lockedTask->workflow_instance_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTask->status !== WorkflowTaskStatus::Pending) {
                throw new TaskNotPendingException('Task is not pending.');
            }

            if ($instance->status !== WorkflowInstanceStatus::Running) {
                throw new TaskNotPendingException('Workflow instance is not running.');
            }

            if ($operationKey !== null) {
                $existing = $this->findByOperationKey($lockedTask, $operationKey);

                if ($existing instanceof WorkflowTaskAssignment) {
                    return $this->resolveIdempotentReplay($instance, $lockedTask, $existing, $toUserId, $fromActor, $comment);
                }
            }

            $fromUserId = $requireActor ? $this->resolveActorUserId($fromActor) : null;

            if ($requireActor && $fromUserId === null) {
                throw new UserCannotReassignTaskException('Actor user id is invalid.');
            }

            if ($fromUserId !== null) {
                $fromUserId = (string) $fromUserId;
            }

            $fromAssignment = $requireActor
                ? $this->lockPendingAssignmentForActor($lockedTask, $fromUserId, $assignmentId)
                : $this->lockFirstPendingAssignment($lockedTask);

            if ($fromAssignment->effectiveAssigneeUserId() === $toUserId) {
                if ($allowSameTargetNoOp) {
                    return $instance->refresh();
                }

                throw new UserCannotReassignTaskException('Cannot reassign a task to the same assignee.');
            }

            if ($fromUserId !== null && $fromUserId === $toUserId && $fromAssignment->assignee_user_id === $toUserId) {
                if ($allowSameTargetNoOp) {
                    return $instance->refresh();
                }

                throw new UserCannotReassignTaskException('Cannot reassign a task to the same assignee.');
            }

            $approvalMode = $lockedTask->approval_mode ?? ApprovalMode::Any;

            if ($approvalMode->isSequential() && $requireActor) {
                $this->assertActorIsCurrentSequentialAssignee($lockedTask, $fromAssignment);
            }

            $targetAlreadyAssigned = WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $lockedTask->getKey())
                ->where('assignee_user_id', $toUserId)
                ->lockForUpdate()
                ->exists();

            if ($targetAlreadyAssigned) {
                throw new UserCannotReassignTaskException('Target user already has an assignment for this task.');
            }

            $originalResponsible = $fromAssignment->originalAssigneeUserId();
            $previousEffective = $fromAssignment->effectiveAssigneeUserId();

            $fromAssignment->forceFill([
                'status' => WorkflowTaskAssignmentStatus::Reassigned,
                'acted_at' => now(),
            ])->save();

            try {
                $newAssignment = WorkflowTaskAssignment::query()->create([
                    'workflow_task_id' => $lockedTask->getKey(),
                    'assignee_user_id' => $toUserId,
                    'original_assignee_user_id' => $originalResponsible,
                    'effective_assignee_user_id' => $toUserId,
                    'assignment_source' => $assignmentSource->value,
                    'delegation_id' => null,
                    'previous_assignment_id' => $fromAssignment->getKey(),
                    'reassignment_operation_key' => $operationKey,
                    'status' => WorkflowTaskAssignmentStatus::Pending,
                    'sequence' => $fromAssignment->sequence,
                ]);
            } catch (QueryException $exception) {
                if ($operationKey !== null) {
                    $existing = $this->findByOperationKey($lockedTask, $operationKey);

                    if ($existing instanceof WorkflowTaskAssignment) {
                        return $this->resolveIdempotentReplay(
                            $instance,
                            $lockedTask,
                            $existing,
                            $toUserId,
                            $fromActor,
                            $comment,
                        );
                    }
                }

                throw $exception;
            }

            $payload = [
                'from_assignee_user_id' => $fromUserId !== null ? (string) $fromUserId : $previousEffective,
                'to_assignee_user_id' => $toUserId,
                'assignment_id' => $newAssignment->getKey(),
                'previous_assignment_id' => $fromAssignment->getKey(),
                'original_assignee_user_id' => $originalResponsible,
                'previous_effective_assignee_user_id' => $previousEffective,
                'new_effective_assignee_user_id' => $toUserId,
                'assignment_source' => $assignmentSource->value,
                'delegation_id' => $fromAssignment->delegation_id,
                'idempotency_key' => $operationKey,
                'occurred_at' => now()->utc()->toIso8601String(),
            ];

            $this->logger->log(
                $instance,
                WorkflowLogEvent::TaskReassigned,
                $lockedTask,
                $fromActor,
                $comment,
                $payload,
            );

            $instance = $instance->refresh();

            event(new TaskReassigned(
                $lockedTask->refresh(),
                $instance,
                $fromAssignment->refresh(),
                $newAssignment->refresh(),
                $fromActor,
                $comment,
            ));

            $this->taskHooksForInstance($this->taskHooksRegistry, $instance)
                ->onReassigned($lockedTask->refresh(), $instance, $fromActor, $toUserId);

            return $instance;
        });
    }

    private function normalizeOperationKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $key = trim($idempotencyKey);

        return $key === '' ? null : $key;
    }

    private function findByOperationKey(WorkflowTask $task, string $operationKey): ?WorkflowTaskAssignment
    {
        // Task row is already locked; unique index enforces operation-key uniqueness.
        $existing = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('reassignment_operation_key', $operationKey)
            ->first();

        return $existing instanceof WorkflowTaskAssignment ? $existing : null;
    }

    private function resolveIdempotentReplay(
        WorkflowInstance $instance,
        WorkflowTask $task,
        WorkflowTaskAssignment $existing,
        string $toUserId,
        mixed $fromActor,
        ?string $comment,
    ): WorkflowInstance {
        unset($task, $fromActor, $comment);

        if ((string) $existing->assignee_user_id !== $toUserId
            && $existing->effectiveAssigneeUserId() !== $toUserId) {
            throw new IdempotencyConflictException(
                'Reassignment idempotency key was already used with a different target.',
            );
        }

        return $instance->refresh();
    }

    private function lockFirstPendingAssignment(WorkflowTask $task): WorkflowTaskAssignment
    {
        $assignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $assignment instanceof WorkflowTaskAssignment) {
            throw new UserCannotReassignTaskException('No pending assignment available for escalation.');
        }

        return $assignment;
    }

    private function lockPendingAssignmentForActor(
        WorkflowTask $task,
        string $fromUserId,
        int|string|null $assignmentId = null,
    ): WorkflowTaskAssignment {
        if ($assignmentId !== null && $assignmentId !== '') {
            $targeted = WorkflowTaskAssignment::query()
                ->whereKey($assignmentId)
                ->where('workflow_task_id', $task->getKey())
                ->where('status', WorkflowTaskAssignmentStatus::Pending)
                ->lockForUpdate()
                ->first();

            if (! $targeted instanceof WorkflowTaskAssignment || ! $targeted->isActionableBy($fromUserId)) {
                throw new UserCannotReassignTaskException('Actor does not have a pending assignment for this task.');
            }

            return $targeted;
        }

        $byAssignee = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('assignee_user_id', $fromUserId)
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->lockForUpdate()
            ->first();

        if ($byAssignee instanceof WorkflowTaskAssignment) {
            return $byAssignee;
        }

        $matches = WorkflowTaskAssignment::constrainActionableBy(
            WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $task->getKey())
                ->where('status', WorkflowTaskAssignmentStatus::Pending)
                ->orderBy('id'),
            $fromUserId,
        )
            ->lockForUpdate()
            ->get();

        if ($matches->count() === 0) {
            throw new UserCannotReassignTaskException('Actor does not have a pending assignment for this task.');
        }

        if ($matches->count() > 1) {
            throw new UserCannotReassignTaskException(
                'Actor represents multiple pending assignments for this task; pass assignmentId to reassign a specific original assignment.',
            );
        }

        $match = $matches->first();

        if (! $match instanceof WorkflowTaskAssignment) {
            throw new UserCannotReassignTaskException('Actor does not have a pending assignment for this task.');
        }

        return $match;
    }

    private function assertActorIsCurrentSequentialAssignee(
        WorkflowTask $task,
        WorkflowTaskAssignment $fromAssignment,
    ): void {
        $currentSequence = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->whereNotNull('sequence')
            ->min('sequence');

        if ($currentSequence === null) {
            throw new UserCannotReassignTaskException('No sequential assignment is currently active for this task.');
        }

        if ((int) $fromAssignment->sequence !== (int) $currentSequence) {
            throw new UserCannotReassignTaskException('Only the current sequential assignee may reassign this task.');
        }
    }
}
