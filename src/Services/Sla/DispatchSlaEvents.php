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
use DbflowLabs\Core\Enums\SlaEventType;
use DbflowLabs\Core\Jobs\ProcessSlaEventJob;
use DbflowLabs\Core\Models\WorkflowSlaEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DispatchSlaEvents
{
    /**
     * @return array{claimed: int, dispatched: int}
     */
    public function handle(
        ?Carbon $asOf = null,
        ?int $limit = null,
        ?SlaEventType $type = null,
        bool $dryRun = false,
    ): array {
        $asOf ??= Carbon::now('UTC');
        $limit ??= (int) config('dbflow.sla.dispatch_batch_size', 100);
        $claimed = 0;
        $dispatched = 0;

        $query = WorkflowSlaEvent::query()
            ->where(function ($builder) use ($asOf): void {
                $builder
                    ->whereIn('status', [
                        SlaEventStatus::Pending->value,
                        SlaEventStatus::Failed->value,
                    ])
                    ->whereColumn('attempts', '<', 'max_attempts')
                    ->where(function ($ready) use ($asOf): void {
                        $ready->whereNull('next_attempt_at')
                            ->orWhere('next_attempt_at', '<=', $asOf);
                    });
            })
            ->where('scheduled_at', '<=', $asOf)
            ->whereNull('cancelled_at')
            ->orderBy('scheduled_at')
            ->orderBy('id');

        if ($type instanceof SlaEventType) {
            $query->where('event_type', $type);
        }

        $candidateIds = $query->limit($limit)->pluck('id');

        foreach ($candidateIds as $eventId) {
            $event = $this->claimEvent((int) $eventId, $asOf, $dryRun);

            if ($event === null) {
                continue;
            }

            $claimed++;

            if ($dryRun) {
                continue;
            }

            ProcessSlaEventJob::dispatch((int) $event->getKey(), (int) $event->attempts);
            $dispatched++;
        }

        return ['claimed' => $claimed, 'dispatched' => $dispatched];
    }

    private function claimEvent(int $eventId, Carbon $asOf, bool $dryRun): ?WorkflowSlaEvent
    {
        return DB::transaction(function () use ($eventId, $asOf, $dryRun): ?WorkflowSlaEvent {
            /** @var WorkflowSlaEvent|null $event */
            $event = WorkflowSlaEvent::query()->whereKey($eventId)->lockForUpdate()->first();

            if ($event === null || ! $event->isClaimable()) {
                return null;
            }

            if ($event->scheduled_at !== null && $event->scheduled_at->gt($asOf)) {
                return null;
            }

            if ($event->next_attempt_at !== null && $event->next_attempt_at->gt($asOf)) {
                return null;
            }

            if ($dryRun) {
                return $event;
            }

            $event->forceFill([
                'status' => SlaEventStatus::Processing,
                'processing_started_at' => $asOf,
                'attempts' => $event->attempts + 1,
                'next_attempt_at' => null,
            ])->save();

            return $event->refresh();
        });
    }
}
