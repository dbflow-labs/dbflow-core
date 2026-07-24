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

use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Enums\ActionExecutionStatus;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Events\ActionExecutionRecovered;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RecoverStaleActionExecutions
{
    public function __construct(
        private readonly CancelActionExecutions $cancelActionExecutions,
    ) {}

    /**
     * @return array{recovered: int, cancelled: int, exhausted: int}
     */
    public function handle(?Carbon $asOf = null, ?int $limit = null): array
    {
        $asOf ??= Carbon::now('UTC');
        $thresholdSeconds = (int) config('dbflow.actions.stale_processing_threshold_seconds', 900);
        $staleBefore = $asOf->copy()->subSeconds($thresholdSeconds);
        $limit ??= (int) config('dbflow.actions.recovery_batch_size', 100);

        $recovered = 0;
        $cancelled = 0;
        $exhausted = 0;

        $candidateIds = WorkflowActionExecution::query()
            ->where('status', ActionExecutionStatus::Running)
            ->whereNull('succeeded_at')
            ->whereNull('cancelled_at')
            ->where('processing_started_at', '<=', $staleBefore)
            ->orderBy('processing_started_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($candidateIds as $executionId) {
            $outcome = DB::transaction(function () use ($executionId, $asOf): string {
                /** @var WorkflowActionExecution|null $execution */
                $execution = WorkflowActionExecution::query()->whereKey($executionId)->lockForUpdate()->first();

                if ($execution === null || $execution->status !== ActionExecutionStatus::Running) {
                    return 'skipped';
                }

                $instance = WorkflowInstance::query()->find($execution->workflow_instance_id);

                if ($this->shouldCancelForInstanceState($execution, $instance)) {
                    if ($instance instanceof WorkflowInstance) {
                        $this->cancelActionExecutions->cancelForInstance($instance, 'stale_recovery_instance_terminal');
                    } else {
                        $execution->forceFill([
                            'status' => ActionExecutionStatus::Cancelled,
                            'cancelled_at' => $asOf,
                            'processing_started_at' => null,
                            'result_metadata' => array_merge($execution->result_metadata ?? [], [
                                'cancel_reason' => 'stale_recovery_missing_instance',
                            ]),
                        ])->save();
                    }

                    return 'cancelled';
                }

                if ($execution->attempts >= $execution->max_attempts) {
                    $execution->forceFill([
                        'status' => ActionExecutionStatus::Exhausted,
                        'exhausted_at' => $asOf,
                        'processing_started_at' => null,
                        'last_error' => 'Stale processing recovery exhausted attempts.',
                        'result_metadata' => array_merge($execution->result_metadata ?? [], [
                            'recovered_at' => $asOf->toIso8601String(),
                            'recovery_outcome' => 'exhausted',
                        ]),
                    ])->save();

                    return 'exhausted';
                }

                $execution->forceFill([
                    'status' => ActionExecutionStatus::Queued,
                    'processing_started_at' => null,
                    'next_attempt_at' => $asOf,
                    'result_metadata' => array_merge($execution->result_metadata ?? [], [
                        'recovered_at' => $asOf->toIso8601String(),
                        'recovery_outcome' => 'recovered',
                    ]),
                ])->save();

                return 'recovered';
            });

            if ($outcome === 'recovered') {
                $recovered++;
                $execution = WorkflowActionExecution::query()->find($executionId);

                if ($execution instanceof WorkflowActionExecution) {
                    event(new ActionExecutionRecovered($execution, [
                        'attempts' => $execution->attempts,
                        'logical_execution_key' => $execution->logical_execution_key,
                    ]));
                }
            } elseif ($outcome === 'cancelled') {
                $cancelled++;
            } elseif ($outcome === 'exhausted') {
                $exhausted++;
            }
        }

        return compact('recovered', 'cancelled', 'exhausted');
    }

    private function shouldCancelForInstanceState(
        WorkflowActionExecution $execution,
        ?WorkflowInstance $instance,
    ): bool {
        if ($instance === null) {
            return true;
        }

        // Workflow cancellation always cancels outstanding executions.
        if ($instance->status === WorkflowInstanceStatus::Cancelled) {
            return true;
        }

        // Non-blocking executions may outlive a completed workflow and must remain recoverable.
        if ($execution->execution_mode === ActionExecutionMode::ReliableNonBlocking) {
            return false;
        }

        // Blocking executions require a running instance to remain meaningful.
        return $instance->status !== WorkflowInstanceStatus::Running;
    }
}
