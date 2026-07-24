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

namespace DbflowLabs\Core\Services\Sla;

use DbflowLabs\Core\Enums\SlaEventStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\SlaEventRecovered;
use DbflowLabs\Core\Models\WorkflowSlaEvent;
use DbflowLabs\Core\Models\WorkflowTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RecoverStaleSlaEvents
{
    public function __construct(
        private readonly CancelTaskSlaEvents $cancelTaskSlaEvents,
    ) {}

    /**
     * @return array{recovered: int, cancelled: int, exhausted: int}
     */
    public function handle(?Carbon $asOf = null, ?int $limit = null): array
    {
        $asOf ??= Carbon::now('UTC');
        $thresholdSeconds = (int) config('dbflow.sla.stale_processing_threshold_seconds', 900);
        $staleBefore = $asOf->copy()->subSeconds($thresholdSeconds);
        $limit ??= (int) config('dbflow.sla.recovery_batch_size', 100);

        $recovered = 0;
        $cancelled = 0;
        $exhausted = 0;

        $candidateIds = WorkflowSlaEvent::query()
            ->where('status', SlaEventStatus::Processing)
            ->whereNull('processed_at')
            ->whereNull('cancelled_at')
            ->where('processing_started_at', '<=', $staleBefore)
            ->orderBy('processing_started_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($candidateIds as $eventId) {
            $outcome = DB::transaction(function () use ($eventId, $asOf): string {
                /** @var WorkflowSlaEvent|null $event */
                $event = WorkflowSlaEvent::query()->whereKey($eventId)->lockForUpdate()->first();

                if ($event === null || $event->status !== SlaEventStatus::Processing) {
                    return 'skipped';
                }

                $task = WorkflowTask::query()->find($event->workflow_task_id);

                if ($task !== null && $task->status !== WorkflowTaskStatus::Pending) {
                    $this->cancelTaskSlaEvents->cancelForTask($task, 'stale_recovery_task_terminal');

                    return 'cancelled';
                }

                if ($event->attempts >= $event->max_attempts) {
                    $event->forceFill([
                        'status' => SlaEventStatus::Failed,
                        'failed_at' => $asOf,
                        'processing_started_at' => null,
                        'last_error' => 'Stale processing recovery exhausted attempts.',
                        'result_metadata' => array_merge($event->result_metadata ?? [], [
                            'recovered_at' => $asOf->toIso8601String(),
                            'recovery_outcome' => 'exhausted',
                        ]),
                    ])->save();

                    return 'exhausted';
                }

                // Return to pending for the dispatcher. Do not dispatch a job here with a
                // fabricated expectedAttempt — that would fail ProcessSlaEvent's claim check.
                $event->forceFill([
                    'status' => SlaEventStatus::Pending,
                    'processing_started_at' => null,
                    'next_attempt_at' => $asOf,
                    'result_metadata' => array_merge($event->result_metadata ?? [], [
                        'recovered_at' => $asOf->toIso8601String(),
                        'recovery_outcome' => 'recovered',
                    ]),
                ])->save();

                return 'recovered';
            });

            if ($outcome === 'recovered') {
                $recovered++;
                $event = WorkflowSlaEvent::query()->find($eventId);

                if ($event instanceof WorkflowSlaEvent) {
                    event(new SlaEventRecovered($event, [
                        'attempts' => $event->attempts,
                        'idempotency_key' => $event->idempotency_key,
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
}
