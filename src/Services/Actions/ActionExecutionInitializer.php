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

use DbflowLabs\Core\Actions\ActionRetryPolicy;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Enums\ActionExecutionStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Events\ActionExecutionQueued;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ActionExecutionInitializer
{
    use ResolvesActorUserId;

    public function __construct(
        private readonly ActionVisitIdentityAllocator $visitIdentityAllocator,
        private readonly WorkflowLogger $logger,
    ) {}

    public function initialize(
        WorkflowInstance $instance,
        ActionNode $node,
        mixed $actor = null,
        ?int $workflowTaskId = null,
    ): WorkflowActionExecution {
        return DB::transaction(function () use ($instance, $node, $actor, $workflowTaskId): WorkflowActionExecution {
            $active = WorkflowActionExecution::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('node_key', $node->key())
                ->whereIn('status', [
                    ActionExecutionStatus::Queued->value,
                    ActionExecutionStatus::Running->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($active instanceof WorkflowActionExecution) {
                return $active;
            }

            $visitSequence = $this->visitIdentityAllocator->allocate($instance, $node->key());
            $logicalKey = $this->visitIdentityAllocator->buildLogicalExecutionKey(
                (int) $instance->getKey(),
                $node->key(),
                $visitSequence,
            );

            $existing = WorkflowActionExecution::query()
                ->where('logical_execution_key', $logicalKey)
                ->first();

            if ($existing instanceof WorkflowActionExecution) {
                return $existing;
            }

            $retryPolicy = ActionRetryPolicy::fromActionNode($node);
            $now = Carbon::now('UTC');

            $execution = WorkflowActionExecution::query()->create([
                'workflow_instance_id' => $instance->getKey(),
                'workflow_task_id' => $workflowTaskId,
                'node_key' => $node->key(),
                'action_key' => $node->actionKey(),
                'execution_mode' => $node->executionMode(),
                'status' => ActionExecutionStatus::Queued,
                'logical_execution_key' => $logicalKey,
                'visit_sequence' => $visitSequence,
                'attempts' => 0,
                'max_attempts' => $retryPolicy->maxAttempts,
                'queued_at' => $now,
                'node_snapshot' => $node->toArray(),
                'payload_snapshot' => $node->payload(),
                'actor_user_id' => $this->resolveActorUserId($actor),
            ]);

            event(new ActionExecutionQueued($execution->refresh(), $instance->refresh()));

            $this->logger->log(
                $instance,
                WorkflowLogEvent::ActionExecutionQueued,
                actor: $actor,
                payload: [
                    'execution_id' => $execution->getKey(),
                    'logical_execution_key' => $logicalKey,
                    'node_key' => $node->key(),
                    'action_key' => $node->actionKey(),
                    'execution_mode' => $node->executionMode()->value,
                ],
            );

            return $execution->refresh();
        });
    }
}
