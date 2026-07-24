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

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\RejectStrategy;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskCreated;
use DbflowLabs\Core\Events\TaskRejected;
use DbflowLabs\Core\Events\WorkflowRejected;
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
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ApprovalNodeAssigneeResolver;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use DbflowLabs\Core\Support\ResolvesTaskHooks;
use DbflowLabs\Core\Support\ResolvesWorkflowHooks;
use DbflowLabs\Core\Support\TimeoutDueAtResolver;
use Illuminate\Support\Facades\DB;

final class RejectTask
{
    use ResolvesActorUserId;
    use ResolvesTaskHooks;
    use ResolvesWorkflowHooks;

    public function __construct(
        private readonly TransitionResolver $transitionResolver,
        private readonly ApprovalNodeAssigneeResolver $approvalNodeAssigneeResolver,
        private readonly TimeoutDueAtResolver $timeoutDueAtResolver,
        private readonly WorkflowLogger $logger,
        private readonly AssignmentMaterializer $assignmentMaterializer,
        private readonly TaskSlaInitializer $taskSlaInitializer,
        private readonly CancelTaskSlaEvents $cancelTaskSlaEvents,
        private readonly ?WorkflowHooksRegistry $hooksRegistry = null,
        private readonly ?TaskHooksRegistry $taskHooksRegistry = null,
    ) {}

    /**
     * @param  string|null  $targetNodeKey  Only effective for SpecificNode strategy; specifies the rollback target node key
     */
    public function handle(
        WorkflowTask $task,
        mixed $actor = null,
        ?string $comment = null,
        RejectStrategy $strategy = RejectStrategy::Starter,
        ?string $targetNodeKey = null,
    ): WorkflowInstance {
        return DB::transaction(function () use ($task, $actor, $comment, $strategy, $targetNodeKey): WorkflowInstance {
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

            if ($actor !== null) {
                $this->assertActorCanReject($lockedTask, $actorUserId, $approvalMode);
            }

            $this->closeCurrentTaskAssignments($lockedTask, $actorUserId);

            $lockedTask->forceFill([
                'status' => WorkflowTaskStatus::Rejected,
                'completed_at' => now(),
            ])->save();

            $this->cancelTaskSlaEvents->cancelForTask($lockedTask->refresh(), 'task_rejected');

            $this->logger->log(
                $instance,
                WorkflowLogEvent::TaskRejected,
                $lockedTask,
                $actor,
                $comment,
                ['strategy' => $strategy->value],
            );

            $instance = $instance->refresh();
            event(new TaskRejected($lockedTask, $instance, $actor, $comment));
            $this->taskHooksForInstance($this->taskHooksRegistry, $instance)
                ->onAfterReject($lockedTask, $instance, $actor);

            if ($strategy === RejectStrategy::End) {
                return $this->terminateWorkflow($instance, $actor, $comment, $strategy);
            }

            $blueprint = $instance->blueprint();

            $rollbackNodeKey = $this->resolveRollbackNodeKey($strategy, $lockedTask, $blueprint, $targetNodeKey);
            $rollbackNode = $this->transitionResolver->findNode($blueprint, $rollbackNodeKey);

            if (! $rollbackNode instanceof ApprovalNode) {
                throw new InvalidWorkflowDefinitionException(
                    $rollbackNode === null
                        ? "Rollback target node [{$rollbackNodeKey}] not found in workflow definition."
                        : "Rollback target node [{$rollbackNodeKey}] must be an approval node.",
                );
            }

            $nextIteration = ($lockedTask->iteration ?? 1) + 1;

            $this->createRollbackTask($instance, $rollbackNode, $actor, $nextIteration);

            // Rollback keeps the instance `running` (it is not a terminal outcome), so unlike
            // terminateWorkflow() this intentionally does not dispatch WorkflowRejected or call
            // onRejected(): both are documented as terminal, instance-level signals. The audit
            // trail below still records the rollback for observability.
            $this->logger->log(
                $instance,
                WorkflowLogEvent::WorkflowRejected,
                actor: $actor,
                comment: $comment,
                payload: [
                    'strategy' => $strategy->value,
                    'rollback_node_key' => $rollbackNodeKey,
                    'iteration' => $nextIteration,
                    'terminal' => false,
                ],
            );

            return $instance->refresh();
        });
    }

    private function resolveRollbackNodeKey(
        RejectStrategy $strategy,
        WorkflowTask $task,
        Blueprint $blueprint,
        ?string $targetNodeKey,
    ): string {
        return match ($strategy) {
            RejectStrategy::Starter => $this->resolveStarterNodeKey($blueprint),
            RejectStrategy::PreviousNode => $this->resolvePreviousNodeKey($blueprint, $task->node_key),
            RejectStrategy::SpecificNode => $this->resolveSpecificNodeKey($targetNodeKey),
            RejectStrategy::End => throw new InvalidWorkflowDefinitionException('End strategy should not reach here.'),
        };
    }

    private function resolveStarterNodeKey(Blueprint $blueprint): string
    {
        $startNode = $this->transitionResolver->startNode($blueprint);
        $startKey = $startNode->key();

        foreach ($blueprint->transitionsFrom($startKey) as $transition) {
            $toKey = $transition->to();

            if ($toKey === '') {
                continue;
            }

            $node = $blueprint->findNode($toKey);

            if ($node instanceof ApprovalNode) {
                return $toKey;
            }
        }

        throw new InvalidWorkflowDefinitionException(
            'Cannot resolve starter rollback node: no approval node found after start node.',
        );
    }

    private function resolvePreviousNodeKey(Blueprint $blueprint, string $currentNodeKey): string
    {
        foreach ($blueprint->transitionsTo($currentNodeKey) as $transition) {
            $fromKey = $transition->from();

            if ($fromKey === '') {
                continue;
            }

            $fromNode = $blueprint->findNode($fromKey);

            if ($fromNode instanceof ApprovalNode) {
                return $fromKey;
            }
        }

        throw new InvalidWorkflowDefinitionException(
            "Cannot resolve previous approval node for node [{$currentNodeKey}]: no approval predecessor found.",
        );
    }

    private function resolveSpecificNodeKey(?string $targetNodeKey): string
    {
        if (! is_string($targetNodeKey) || $targetNodeKey === '') {
            throw new InvalidWorkflowDefinitionException(
                'SpecificNode rollback strategy requires a non-empty targetNodeKey.',
            );
        }

        return $targetNodeKey;
    }

    private function createRollbackTask(
        WorkflowInstance $instance,
        ApprovalNode $rollbackNode,
        mixed $actor,
        int $iteration,
    ): WorkflowTask {
        $approvalMode = $rollbackNode->approvalMode();
        $nodeKey = $rollbackNode->key();
        $assigneeUserIds = $this->approvalNodeAssigneeResolver->resolveOrFail($instance, $rollbackNode);

        $rollbackTask = WorkflowTask::query()->create([
            'workflow_instance_id' => $instance->getKey(),
            'iteration' => $iteration,
            'node_key' => $nodeKey,
            'node_name' => $rollbackNode->name(),
            'status' => WorkflowTaskStatus::Pending,
            'approval_mode' => $approvalMode,
            'due_at' => $rollbackNode->hasSla()
                ? null
                : $this->timeoutDueAtResolver->resolveDueAt($rollbackNode->timeoutDueIn()),
        ]);

        $this->assignmentMaterializer->createAssignments(
            $rollbackTask,
            $instance,
            $approvalMode,
            $assigneeUserIds,
            is_string($key = $instance->workflow()->value('key')) ? $key : null,
            $nodeKey,
            $rollbackNode->delegationEnabled(),
        );

        $this->taskSlaInitializer->initialize($rollbackTask->refresh(), $instance, $rollbackNode);

        $instance->forceFill(['current_node_key' => $nodeKey])->save();

        $this->logger->log(
            $instance,
            WorkflowLogEvent::TaskCreated,
            $rollbackTask,
            $actor,
            payload: [
                'node_key' => $nodeKey,
                'approval_mode' => $approvalMode->value,
                'assignee_user_ids' => $assigneeUserIds,
                'iteration' => $iteration,
            ],
        );

        $instance = $instance->refresh();
        event(new TaskCreated($rollbackTask, $instance));
        $this->taskHooksForInstance($this->taskHooksRegistry, $instance)
            ->onTaskCreated($rollbackTask, $instance);

        return $rollbackTask;
    }

    private function terminateWorkflow(
        WorkflowInstance $instance,
        mixed $actor,
        ?string $comment,
        RejectStrategy $strategy,
    ): WorkflowInstance {
        $instance->forceFill([
            'status' => WorkflowInstanceStatus::Rejected,
            'completed_at' => now(),
            'active_key' => null,
        ])->save();

        $this->logger->log(
            $instance,
            WorkflowLogEvent::WorkflowRejected,
            actor: $actor,
            comment: $comment,
            payload: ['strategy' => $strategy->value, 'terminal' => true],
        );

        $this->hooksForInstance($this->hooksRegistry, $instance->refresh())->onRejected($instance->refresh());

        event(new WorkflowRejected($instance->refresh()));

        return $instance->refresh();
    }

    private function closeCurrentTaskAssignments(WorkflowTask $task, int|string|null $actorUserId): void
    {
        if ($actorUserId !== null) {
            $assignments = WorkflowTaskAssignment::constrainActionableBy(
                WorkflowTaskAssignment::query()
                    ->where('workflow_task_id', $task->getKey())
                    ->where('status', WorkflowTaskAssignmentStatus::Pending),
                (string) $actorUserId,
            )->get();

            foreach ($assignments as $assignment) {
                $assignment->forceFill([
                    'status' => WorkflowTaskAssignmentStatus::Rejected,
                    'acted_at' => now(),
                ])->save();
            }
        }

        WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->update([
                'status' => WorkflowTaskAssignmentStatus::Skipped,
                'acted_at' => now(),
            ]);
    }

    private function assertActorCanReject(WorkflowTask $task, int|string|null $actorUserId, ApprovalMode $approvalMode): void
    {
        if ($actorUserId === null) {
            throw new UserCannotApproveTaskException('Actor user id is invalid.');
        }

        $assignment = WorkflowTaskAssignment::constrainActionableBy(
            WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $task->getKey())
                ->where('status', WorkflowTaskAssignmentStatus::Pending)
                ->orderBy('id'),
            (string) $actorUserId,
        )->first();

        if ($assignment === null) {
            throw new UserCannotApproveTaskException('Actor does not have a pending assignment for this task.');
        }

        if ($approvalMode->isSequential()) {
            $this->assertActorIsCurrentSequentialAssignee($task, $assignment);
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
            throw new UserCannotApproveTaskException('Only the current sequential assignee may reject this task.');
        }
    }
}
