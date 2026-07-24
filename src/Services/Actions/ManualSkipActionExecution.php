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

use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Enums\ActionExecutionStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Events\ActionExecutionSkipped;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ManualSkipActionExecution
{
    use ResolvesActorUserId;

    public function __construct(
        private readonly AdvanceWorkflowFromAction $advanceWorkflowFromAction,
        private readonly WorkflowLogger $logger,
    ) {}

    public function handle(WorkflowActionExecution $execution, mixed $actor = null, ?string $reason = null): WorkflowActionExecution
    {
        $node = ActionNode::fromArray($execution->node_snapshot);

        if (! $node->allowManualSkip()) {
            throw new InvalidArgumentException('Manual skip is not allowed for this action execution.');
        }

        if (! in_array($execution->status, [
            ActionExecutionStatus::Failed,
            ActionExecutionStatus::Exhausted,
        ], true)) {
            throw new InvalidArgumentException('Only failed or exhausted executions can be manually skipped.');
        }

        $execution = DB::transaction(function () use ($execution, $actor, $reason): WorkflowActionExecution {
            /** @var WorkflowActionExecution $locked */
            $locked = WorkflowActionExecution::query()->whereKey($execution->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [
                ActionExecutionStatus::Failed,
                ActionExecutionStatus::Exhausted,
            ], true)) {
                throw new InvalidArgumentException('Only failed or exhausted executions can be manually skipped.');
            }

            $now = Carbon::now('UTC');

            $locked->forceFill([
                'status' => ActionExecutionStatus::Skipped,
                'skipped_at' => $now,
                'processing_started_at' => null,
                'result_metadata' => array_merge($locked->result_metadata ?? [], [
                    'skip_reason' => $reason,
                    'skipped_by' => $this->resolveActorUserId($actor),
                ]),
            ])->save();

            return $locked->refresh();
        });

        $instance = $execution->workflowInstance;
        $task = $execution->workflowTask;

        if ($instance !== null) {
            $this->logger->log(
                $instance,
                WorkflowLogEvent::ActionExecutionSkipped,
                $task,
                $actor,
                comment: $reason,
                payload: [
                    'execution_id' => $execution->getKey(),
                    'logical_execution_key' => $execution->logical_execution_key,
                ],
            );
        }

        event(new ActionExecutionSkipped($execution, $instance, $task, ['reason' => $reason]));

        if ($execution->isBlocking()) {
            $this->advanceWorkflowFromAction->handle($execution, $instance, $task, $actor);
        }

        return $execution->refresh();
    }
}
