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

use DbflowLabs\Core\Contracts\WorkflowContextInterface;
use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskApproved;
use DbflowLabs\Core\Events\TaskCreated;
use DbflowLabs\Core\Events\WorkflowCompleted;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Exceptions\TaskNotPendingException;
use DbflowLabs\Core\Exceptions\UserCannotApproveTaskException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\AssignmentMaterializer;
use DbflowLabs\Core\Services\Sla\CancelTaskSlaEvents;
use DbflowLabs\Core\Services\Sla\TaskSlaInitializer;
use DbflowLabs\Core\Services\TaskHooksRegistry;
use DbflowLabs\Core\Services\TransitionResolver;
use DbflowLabs\Core\Services\WorkflowNodeTraverser;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ApprovalNodeAssigneeResolver;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use DbflowLabs\Core\Support\ResolvesTaskHooks;
use DbflowLabs\Core\Support\ResolvesWorkflowHooks;
use DbflowLabs\Core\Support\TimeoutDueAtResolver;
use DbflowLabs\Core\Support\WorkflowCompletionStatus;
use Illuminate\Support\Facades\DB;

final class ApproveTask
{
    use ResolvesActorUserId;
    use ResolvesTaskHooks;
    use ResolvesWorkflowHooks;

    public function __construct(
        private readonly TransitionResolver $transitionResolver,
        private readonly WorkflowNodeTraverser $nodeTraverser,
        private readonly ApprovalNodeAssigneeResolver $approvalNodeAssigneeResolver,
        private readonly TimeoutDueAtResolver $timeoutDueAtResolver,
        private readonly WorkflowLogger $logger,
        private readonly AssignmentMaterializer $assignmentMaterializer,
        private readonly TaskSlaInitializer $taskSlaInitializer,
        private readonly CancelTaskSlaEvents $cancelTaskSlaEvents,
        private readonly ?WorkflowHooksRegistry $hooksRegistry = null,
        private readonly ?TaskHooksRegistry $taskHooksRegistry = null,
    ) {}

    public function handle(WorkflowTask $task, mixed $actor = null, ?string $comment = null): WorkflowInstance
    {
        return DB::transaction(function () use ($task, $actor, $comment): WorkflowInstance {
            /** @var WorkflowTask $lockedTask */
            $lockedTask = WorkflowTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

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

            $actorUserId = $this->resolveActorUserId($actor);
            $approvalMode = $lockedTask->approval_mode ?? ApprovalMode::Any;

            // Unlike RejectTask (which allows a null system actor for auto-reject-on-timeout),
            // approval always requires a real assignee; there is no legitimate system-approve path.
            $this->assertActorCanApprove($lockedTask, $actorUserId, $approvalMode);

            $taskApproved = $this->applyApprovalMode($lockedTask, $approvalMode, $actorUserId);

            if (! $taskApproved) {
                return $instance->refresh();
            }

            $lockedTask->forceFill([
                'status' => WorkflowTaskStatus::Approved,
                'completed_at' => now(),
            ])->save();

            $this->cancelTaskSlaEvents->cancelForTask($lockedTask->refresh(), 'task_approved');

            $this->logger->log(
                $instance,
                WorkflowLogEvent::TaskApproved,
                $lockedTask,
                $actor,
                $comment,
            );

            $instance = $instance->refresh();
            event(new TaskApproved($lockedTask, $instance, $actor, $comment));
            $this->taskHooksForInstance($this->taskHooksRegistry, $instance)
                ->onAfterApprove($lockedTask, $instance, $actor);

            return $this->advanceWorkflow($instance, $lockedTask, $actor);
        });
    }

    private function assertActorCanApprove(WorkflowTask $task, int|string|null $actorUserId, ApprovalMode $approvalMode): void
    {
        if ($actorUserId === null) {
            throw new UserCannotApproveTaskException('Actor user id is invalid.');
        }

        $assignments = $this->pendingAssignmentsForActor($task, (string) $actorUserId, $approvalMode);

        if ($assignments->isEmpty()) {
            throw new UserCannotApproveTaskException('Actor does not have a pending assignment for this task.');
        }

        if ($approvalMode->isSequential()) {
            $this->assertActorIsCurrentSequentialAssignee($task, $assignments->first());
        }
    }

    private function assertActorIsCurrentSequentialAssignee(WorkflowTask $task, WorkflowTaskAssignment $assignment): void
    {
        $currentSequence = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->whereNotNull('sequence')
            ->min('sequence');

        if ($currentSequence === null || (int) $assignment->sequence !== (int) $currentSequence) {
            throw new UserCannotApproveTaskException('Only the current sequential assignee may approve this task.');
        }
    }

    private function applyApprovalMode(WorkflowTask $task, ApprovalMode $approvalMode, int|string|null $actorUserId): bool
    {
        if ($actorUserId !== null) {
            $assignments = $this->pendingAssignmentsForActor($task, (string) $actorUserId, $approvalMode);

            if ($approvalMode->isAny()) {
                /** @var WorkflowTaskAssignment|null $first */
                $first = $assignments->first();

                if ($first instanceof WorkflowTaskAssignment) {
                    $first->forceFill([
                        'status' => WorkflowTaskAssignmentStatus::Approved,
                        'acted_at' => now(),
                    ])->save();
                }
            } else {
                foreach ($assignments as $assignment) {
                    $assignment->forceFill([
                        'status' => WorkflowTaskAssignmentStatus::Approved,
                        'acted_at' => now(),
                    ])->save();
                }
            }
        }

        if ($approvalMode->isAny()) {
            WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $task->getKey())
                ->where('status', WorkflowTaskAssignmentStatus::Pending)
                ->update([
                    'status' => WorkflowTaskAssignmentStatus::Skipped,
                    'acted_at' => now(),
                ]);

            return true;
        }

        if ($approvalMode->isAll() || $approvalMode->isSequential()) {
            $pendingCount = WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $task->getKey())
                ->where('status', WorkflowTaskAssignmentStatus::Pending)
                ->count();

            return $pendingCount === 0;
        }

        return true;
    }

    /**
     * @return \Illuminate\Support\Collection<int, WorkflowTaskAssignment>
     */
    private function pendingAssignmentsForActor(
        WorkflowTask $task,
        string $actorUserId,
        ApprovalMode $approvalMode,
    ) {
        $query = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->orderBy('id');

        $query = WorkflowTaskAssignment::constrainActionableBy($query, $actorUserId);

        if ($approvalMode->isSequential()) {
            $currentSequence = WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $task->getKey())
                ->where('status', WorkflowTaskAssignmentStatus::Pending)
                ->whereNotNull('sequence')
                ->min('sequence');

            if ($currentSequence !== null) {
                $query->where('sequence', (int) $currentSequence);
            }
        }

        return $query->get();
    }

    private function advanceWorkflow(WorkflowInstance $instance, WorkflowTask $task, mixed $actor): WorkflowInstance
    {
        $blueprint = $instance->blueprint();

        $workflowable = $instance->workflowable;
        $metadata = is_array($instance->metadata) ? $instance->metadata : [];

        $variables = $workflowable instanceof WorkflowContextInterface
            ? $workflowable->getWorkflowVariables()
            : (is_array($metadata['variables'] ?? null) ? $metadata['variables'] : []);

        $nextNode = $this->transitionResolver->nextNode(
            blueprint: $blueprint,
            fromNodeKey: $task->node_key,
            variables: $variables,
        );

        if ($nextNode === null) {
            $this->markCompleted($instance, null, $actor);
            $this->dispatchCompletionHooks($instance->refresh());

            return $instance->refresh();
        }

        $result = $this->nodeTraverser->traverse(
            $instance,
            $blueprint,
            $nextNode,
            $variables,
            $actor,
            function (WorkflowInstance $i, ApprovalNode $n, mixed $a): void {
                $this->createNextApprovalTask($i, $n, $a);
            },
            function (WorkflowInstance $i, EndNode $n, mixed $a): void {
                $this->markCompleted($i, $n, $a);
            },
        );

        if ($result === 'completed') {
            $this->dispatchCompletionHooks($instance->refresh());
        }

        return $instance->refresh();
    }

    private function markCompleted(WorkflowInstance $instance, ?EndNode $endNode, mixed $actor): void
    {
        $instance->forceFill([
            'status' => WorkflowCompletionStatus::fromEndNode($endNode),
            'current_node_key' => $endNode?->key() ?? $instance->current_node_key,
            'completed_at' => now(),
            'active_key' => null,
        ])->save();

        $this->logger->log($instance, WorkflowLogEvent::WorkflowCompleted, actor: $actor);

        event(new WorkflowCompleted($instance->refresh()));
    }

    private function dispatchCompletionHooks(WorkflowInstance $instance): void
    {
        $hooks = $this->hooksForInstance($this->hooksRegistry, $instance);

        match ($instance->status) {
            WorkflowInstanceStatus::Rejected => $hooks->onRejected($instance),
            WorkflowInstanceStatus::Cancelled => $hooks->onCancelled($instance),
            default => $hooks->onApproved($instance),
        };
    }

    private function createNextApprovalTask(WorkflowInstance $instance, ApprovalNode $node, mixed $actor): WorkflowTask
    {
        $approvalMode = $node->approvalMode();
        $nodeKey = $node->key();
        $assigneeUserIds = $this->approvalNodeAssigneeResolver->resolveOrFail($instance, $node);

        $task = WorkflowTask::query()->create([
            'workflow_instance_id' => $instance->getKey(),
            'node_key' => $nodeKey,
            'node_name' => $node->name(),
            'status' => WorkflowTaskStatus::Pending,
            'approval_mode' => $approvalMode,
            'due_at' => $node->hasSla()
                ? null
                : $this->timeoutDueAtResolver->resolveDueAt($node->timeoutDueIn()),
        ]);

        $this->assignmentMaterializer->createAssignments(
            $task,
            $instance,
            $approvalMode,
            $assigneeUserIds,
            is_string($key = $instance->workflow()->value('key')) ? $key : null,
            $nodeKey,
            $node->delegationEnabled(),
        );

        $this->taskSlaInitializer->initialize($task->refresh(), $instance, $node);

        $instance->forceFill(['current_node_key' => $nodeKey])->save();

        $this->logger->log(
            $instance,
            WorkflowLogEvent::TaskCreated,
            $task,
            $actor,
            payload: [
                'node_key' => $nodeKey,
                'approval_mode' => $approvalMode->value,
                'assignee_user_ids' => $assigneeUserIds,
            ],
        );

        $instance = $instance->refresh();
        event(new TaskCreated($task, $instance));
        $this->taskHooksForInstance($this->taskHooksRegistry, $instance)
            ->onTaskCreated($task, $instance);

        return $task;
    }

}
