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
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskReassigned;
use DbflowLabs\Core\Exceptions\TaskNotPendingException;
use DbflowLabs\Core\Exceptions\UserCannotReassignTaskException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\TaskHooksRegistry;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use DbflowLabs\Core\Support\ResolvesTaskHooks;
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
    ): WorkflowInstance {
        $toUserId = trim($toUserId);

        if ($toUserId === '') {
            throw new UserCannotReassignTaskException('Target assignee user id is required.');
        }

        return DB::transaction(function () use ($task, $fromActor, $toUserId, $comment): WorkflowInstance {
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

            $fromUserId = $this->resolveActorUserId($fromActor);

            if ($fromUserId === null) {
                throw new UserCannotReassignTaskException('Actor user id is invalid.');
            }

            if ($fromUserId === $toUserId) {
                throw new UserCannotReassignTaskException('Cannot reassign a task to the same assignee.');
            }

            /** @var WorkflowTaskAssignment|null $fromAssignment */
            $fromAssignment = WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $lockedTask->getKey())
                ->where('assignee_user_id', $fromUserId)
                ->where('status', WorkflowTaskAssignmentStatus::Pending)
                ->lockForUpdate()
                ->first();

            if ($fromAssignment === null) {
                throw new UserCannotReassignTaskException('Actor does not have a pending assignment for this task.');
            }

            $approvalMode = $lockedTask->approval_mode ?? ApprovalMode::Any;

            if ($approvalMode->isSequential()) {
                $this->assertActorIsCurrentSequentialAssignee($lockedTask, $fromAssignment);
            }

            $targetAlreadyAssigned = WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $lockedTask->getKey())
                ->where('assignee_user_id', $toUserId)
                ->exists();

            if ($targetAlreadyAssigned) {
                throw new UserCannotReassignTaskException('Target user already has an assignment for this task.');
            }

            $fromAssignment->forceFill([
                'status' => WorkflowTaskAssignmentStatus::Reassigned,
                'acted_at' => now(),
            ])->save();

            $newAssignment = WorkflowTaskAssignment::query()->create([
                'workflow_task_id' => $lockedTask->getKey(),
                'assignee_user_id' => $toUserId,
                'status' => WorkflowTaskAssignmentStatus::Pending,
                'sequence' => $fromAssignment->sequence,
            ]);

            $payload = [
                'from_assignee_user_id' => (string) $fromUserId,
                'to_assignee_user_id' => $toUserId,
                'assignment_id' => $newAssignment->getKey(),
                'previous_assignment_id' => $fromAssignment->getKey(),
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
