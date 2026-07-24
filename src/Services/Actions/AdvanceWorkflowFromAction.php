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

namespace DbflowLabs\Core\Services\Actions;

use DbflowLabs\Core\Contracts\WorkflowContextInterface;
use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Enums\ActionExecutionStatus;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskCreated;
use DbflowLabs\Core\Events\WorkflowCompleted;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\AssignmentMaterializer;
use DbflowLabs\Core\Services\Sla\TaskSlaInitializer;
use DbflowLabs\Core\Services\TaskHooksRegistry;
use DbflowLabs\Core\Services\TransitionResolver;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Services\WorkflowNodeTraverser;
use DbflowLabs\Core\Support\ApprovalNodeAssigneeResolver;
use DbflowLabs\Core\Support\ResolvesTaskHooks;
use DbflowLabs\Core\Support\TimeoutDueAtResolver;
use DbflowLabs\Core\Support\WorkflowCompletionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AdvanceWorkflowFromAction
{
    use ResolvesTaskHooks;

    public function __construct(
        private readonly TransitionResolver $transitionResolver,
        private readonly WorkflowNodeTraverser $nodeTraverser,
        private readonly WorkflowLogger $logger,
        private readonly ApprovalNodeAssigneeResolver $approvalNodeAssigneeResolver,
        private readonly AssignmentMaterializer $assignmentMaterializer,
        private readonly TimeoutDueAtResolver $timeoutDueAtResolver,
        private readonly TaskSlaInitializer $taskSlaInitializer,
        private readonly ?TaskHooksRegistry $taskHooksRegistry = null,
    ) {}

    public function handle(
        WorkflowActionExecution $execution,
        ?WorkflowInstance $instance = null,
        ?WorkflowTask $task = null,
        mixed $actor = null,
    ): bool {
        if (! $execution->isBlocking()) {
            return false;
        }

        if (! in_array($execution->status, [ActionExecutionStatus::Succeeded, ActionExecutionStatus::Skipped], true)) {
            return false;
        }

        return DB::transaction(function () use ($execution, $instance, $task, $actor): bool {
            /** @var WorkflowActionExecution $lockedExecution */
            $lockedExecution = WorkflowActionExecution::query()
                ->whereKey($execution->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedExecution->workflow_advanced_at !== null) {
                return false;
            }

            $instance ??= WorkflowInstance::query()->find($lockedExecution->workflow_instance_id);

            if (! $instance instanceof WorkflowInstance || $instance->status !== WorkflowInstanceStatus::Running) {
                return false;
            }

            /** @var WorkflowInstance $lockedInstance */
            $lockedInstance = WorkflowInstance::query()->whereKey($instance->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedInstance->current_node_key !== $lockedExecution->node_key) {
                return false;
            }

            $now = Carbon::now('UTC');
            $lockedExecution->forceFill(['workflow_advanced_at' => $now])->save();

            $blueprint = Blueprint::fromArray($lockedInstance->definition());
            $variables = $this->resolveVariables($lockedInstance);

            $nextNode = $this->transitionResolver->nextNode(
                $blueprint,
                $lockedExecution->node_key,
                'approve',
                $variables,
            );

            if ($nextNode === null) {
                $this->markCompleted($lockedInstance, null, $actor);

                return true;
            }

            $result = $this->nodeTraverser->traverse(
                $lockedInstance,
                $blueprint,
                $nextNode,
                $variables,
                $actor,
                function (WorkflowInstance $i, ApprovalNode $n, mixed $a) use ($task): void {
                    $this->createNextApprovalTask($i, $n, $a ?? $task?->assignments()->first()?->effectiveAssigneeUserId());
                },
                function (WorkflowInstance $i, EndNode $n, mixed $a): void {
                    $this->markCompleted($i, $n, $a);
                },
            );

            return $result !== 'blocked';
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveVariables(WorkflowInstance $instance): array
    {
        $workflowable = $instance->workflowable;
        $metadata = is_array($instance->metadata) ? $instance->metadata : [];

        if ($workflowable instanceof WorkflowContextInterface) {
            return $workflowable->getWorkflowVariables();
        }

        return is_array($metadata['variables'] ?? null) ? $metadata['variables'] : [];
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
}
