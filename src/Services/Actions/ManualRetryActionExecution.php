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

use DbflowLabs\Core\Enums\ActionExecutionStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Events\ActionExecutionManuallyRetried;
use DbflowLabs\Core\Models\WorkflowActionAttempt;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ManualRetryActionExecution
{
    use ResolvesActorUserId;

    public function __construct(
        private readonly DispatchActionExecutions $dispatchActionExecutions,
        private readonly WorkflowLogger $logger,
    ) {}

    public function handle(WorkflowActionExecution $execution, mixed $actor = null, ?string $reason = null): WorkflowActionExecution
    {
        if (! in_array($execution->status, [
            ActionExecutionStatus::Failed,
            ActionExecutionStatus::Exhausted,
        ], true)) {
            throw new InvalidArgumentException('Only failed or exhausted executions can be manually retried.');
        }

        $execution = DB::transaction(function () use ($execution, $actor, $reason): WorkflowActionExecution {
            /** @var WorkflowActionExecution $locked */
            $locked = WorkflowActionExecution::query()->whereKey($execution->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [
                ActionExecutionStatus::Failed,
                ActionExecutionStatus::Exhausted,
            ], true)) {
                throw new InvalidArgumentException('Execution is not in a retryable terminal state.');
            }

            $now = Carbon::now('UTC');

            $locked->forceFill([
                'status' => ActionExecutionStatus::Queued,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'queued_at' => $now,
                'failed_at' => null,
                'exhausted_at' => null,
                'processing_started_at' => null,
                'last_error' => null,
                'result_metadata' => array_merge($locked->result_metadata ?? [], [
                    'manual_retry_reason' => $reason,
                    'manual_retry_by' => $this->resolveActorUserId($actor),
                ]),
            ])->save();

            WorkflowActionAttempt::query()
                ->where('workflow_action_execution_id', $locked->getKey())
                ->delete();

            return $locked->refresh();
        });

        $instance = $execution->workflowInstance;

        if ($instance !== null) {
            $this->logger->log(
                $instance,
                WorkflowLogEvent::ActionExecutionManuallyRetried,
                $execution->workflowTask,
                $actor,
                comment: $reason,
                payload: [
                    'execution_id' => $execution->getKey(),
                    'logical_execution_key' => $execution->logical_execution_key,
                ],
            );
        }

        event(new ActionExecutionManuallyRetried($execution, ['reason' => $reason]));

        $this->dispatchActionExecutions->queueAfterCommit((int) $execution->getKey());

        return $execution->refresh();
    }
}
