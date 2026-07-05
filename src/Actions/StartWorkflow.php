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

use DbflowLabs\Core\Contracts\Workflowable;
use DbflowLabs\Core\Contracts\WorkflowContextInterface;
use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskCreated;
use DbflowLabs\Core\Events\WorkflowCompleted;
use DbflowLabs\Core\Events\WorkflowStarted;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Exceptions\WorkflowAlreadyRunningException;
use DbflowLabs\Core\Exceptions\WorkflowExceptionTranslator;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\TransitionResolver;
use DbflowLabs\Core\Services\TaskHooksRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionResolver;
use DbflowLabs\Core\Services\WorkflowNodeTraverser;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ApprovalNodeAssigneeResolver;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use DbflowLabs\Core\Support\ResolvesTaskHooks;
use DbflowLabs\Core\Support\ResolvesWorkflowHooks;
use DbflowLabs\Core\Support\WorkflowCompletionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class StartWorkflow
{
    use ResolvesActorUserId;
    use ResolvesTaskHooks;
    use ResolvesWorkflowHooks;

    public function __construct(
        private readonly WorkflowDefinitionResolver $definitionResolver,
        private readonly TransitionResolver $transitionResolver,
        private readonly WorkflowNodeTraverser $nodeTraverser,
        private readonly ApprovalNodeAssigneeResolver $approvalNodeAssigneeResolver,
        private readonly WorkflowLogger $logger,
        private readonly ?WorkflowHooksRegistry $hooksRegistry = null,
        private readonly ?TaskHooksRegistry $taskHooksRegistry = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        string $workflowKey,
        Model $workflowable,
        mixed $startedBy = null,
        array $metadata = [],
    ): WorkflowInstance {
        return DB::transaction(function () use ($workflowKey, $workflowable, $startedBy, $metadata): WorkflowInstance {
            $version = $this->definitionResolver->activeVersion($workflowKey);
            $blueprint = Blueprint::fromArray($version->definition());

            $this->assertNoRunningWorkflow($workflowKey, $workflowable);

            $startNode = $this->transitionResolver->startNode($blueprint);
            $startNodeKey = $startNode->key();

            $variables = $workflowable instanceof WorkflowContextInterface
                ? $workflowable->getWorkflowVariables()
                : (is_array($metadata['variables'] ?? null) ? $metadata['variables'] : []);

            $nextNode = $this->transitionResolver->nextNode(
                blueprint: $blueprint,
                fromNodeKey: $startNodeKey,
                event: 'start',
                variables: $variables,
            );

            if ($nextNode === null) {
                throw new InvalidWorkflowDefinitionException('Workflow definition has no transition from start node.');
            }

            $activeKey = "{$workflowKey}:{$workflowable->getMorphClass()}:{$workflowable->getKey()}";

            try {
                $instance = WorkflowInstance::query()->create([
                    'workflow_id' => $version->workflow_id,
                    'workflow_version_id' => $version->getKey(),
                    'workflowable_type' => $workflowable->getMorphClass(),
                    'workflowable_id' => $workflowable->getKey(),
                    'business_key' => $workflowable instanceof Workflowable
                        ? $workflowable->workflowBusinessKey()
                        : null,
                    'active_key' => $activeKey,
                    'status' => WorkflowInstanceStatus::Running,
                    'current_node_key' => $nextNode->key(),
                    'started_by_user_id' => $this->resolveActorUserId($startedBy),
                    'started_at' => now(),
                    'metadata' => $metadata,
                ]);
            } catch (QueryException $e) {
                WorkflowExceptionTranslator::translateActiveKeyViolation($e);
            }

            $this->logger->log(
                $instance,
                WorkflowLogEvent::WorkflowStarted,
                actor: $startedBy,
                payload: ['workflow_key' => $workflowKey],
            );

            event(new WorkflowStarted($instance->refresh()));

            $result = $this->nodeTraverser->traverse(
                $instance,
                $blueprint,
                $nextNode,
                $variables,
                $startedBy,
                function (WorkflowInstance $i, ApprovalNode $n, mixed $a): void {
                    $this->createApprovalTask($i, $n, $a);
                },
                function (WorkflowInstance $i, EndNode $n, mixed $a): void {
                    $this->markCompleted($i, $n, $a);
                },
            );

            if ($result === 'completed') {
                $this->dispatchCompletionHooks($workflowKey, $instance->refresh());

                return $instance->refresh();
            }

            $this->hooksForKey($this->hooksRegistry, $workflowKey)->onStarted($instance->refresh());

            return $instance->refresh()->load(['tasks.assignments']);
        });
    }

    private function markCompleted(WorkflowInstance $instance, EndNode $endNode, mixed $actor): void
    {
        $instance->forceFill([
            'status' => WorkflowCompletionStatus::fromEndNode($endNode),
            'current_node_key' => $endNode->key(),
            'completed_at' => now(),
            'active_key' => null,
        ])->save();

        $this->logger->log($instance, WorkflowLogEvent::WorkflowCompleted, actor: $actor);

        event(new WorkflowCompleted($instance->refresh()));
    }

    private function dispatchCompletionHooks(string $workflowKey, WorkflowInstance $instance): void
    {
        $hooks = $this->hooksForKey($this->hooksRegistry, $workflowKey);

        match ($instance->status) {
            WorkflowInstanceStatus::Rejected => $hooks->onRejected($instance),
            WorkflowInstanceStatus::Cancelled => $hooks->onCancelled($instance),
            default => $hooks->onApproved($instance),
        };
    }

    private function assertNoRunningWorkflow(string $workflowKey, Model $workflowable): void
    {
        $exists = WorkflowInstance::query()
            ->where('workflowable_type', $workflowable->getMorphClass())
            ->where('workflowable_id', $workflowable->getKey())
            ->where('status', WorkflowInstanceStatus::Running)
            ->whereHas('workflow', static function ($query) use ($workflowKey): void {
                $query->where('key', $workflowKey);
            })
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw new WorkflowAlreadyRunningException('A workflow is already running for this record. Do not submit again.');
        }
    }

    private function createApprovalTask(
        WorkflowInstance $instance,
        ApprovalNode $node,
        mixed $actor,
    ): WorkflowTask {
        $approvalMode = $node->approvalMode();
        $nodeKey = $node->key();
        $assigneeUserIds = $this->approvalNodeAssigneeResolver->resolveOrFail($instance, $node);

        $task = WorkflowTask::query()->create([
            'workflow_instance_id' => $instance->getKey(),
            'node_key' => $nodeKey,
            'node_name' => $node->name(),
            'status' => WorkflowTaskStatus::Pending,
            'approval_mode' => $approvalMode,
        ]);

        $this->createAssignments($task, $approvalMode, $assigneeUserIds);

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

    /**
     * @param  list<int|string>  $assigneeUserIds
     */
    private function createAssignments(WorkflowTask $task, ApprovalMode $approvalMode, array $assigneeUserIds): void
    {
        $sequence = 1;

        foreach ($assigneeUserIds as $assigneeUserId) {
            WorkflowTaskAssignment::query()->create([
                'workflow_task_id' => $task->getKey(),
                'assignee_user_id' => $assigneeUserId,
                'status' => WorkflowTaskAssignmentStatus::Pending,
                'sequence' => $approvalMode->isSequential() ? $sequence++ : null,
            ]);
        }
    }

}
