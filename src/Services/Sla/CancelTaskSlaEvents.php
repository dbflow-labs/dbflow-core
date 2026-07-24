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
use DbflowLabs\Core\Events\SlaEventCancelled;
use DbflowLabs\Core\Models\WorkflowSlaEvent;
use DbflowLabs\Core\Models\WorkflowTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CancelTaskSlaEvents
{
    public function cancelForTask(WorkflowTask $task, ?string $reason = null): int
    {
        if (! TaskSlaInitializer::usesSlaPath($task)) {
            return 0;
        }

        $cancelled = 0;
        $now = Carbon::now('UTC');

        $eventIds = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->whereIn('status', [
                SlaEventStatus::Pending->value,
                SlaEventStatus::Failed->value,
                SlaEventStatus::Processing->value,
            ])
            ->pluck('id');

        foreach ($eventIds as $eventId) {
            $event = DB::transaction(function () use ($eventId, $now, $reason): ?WorkflowSlaEvent {
                /** @var WorkflowSlaEvent|null $locked */
                $locked = WorkflowSlaEvent::query()->whereKey($eventId)->lockForUpdate()->first();

                if ($locked === null) {
                    return null;
                }

                if (in_array($locked->status, [SlaEventStatus::Completed, SlaEventStatus::Cancelled], true)) {
                    return null;
                }

                if ($locked->status === SlaEventStatus::Failed
                    && $locked->attempts >= $locked->max_attempts) {
                    return null;
                }

                $locked->forceFill([
                    'status' => SlaEventStatus::Cancelled,
                    'cancelled_at' => $now,
                    'result_metadata' => array_merge($locked->result_metadata ?? [], [
                        'cancel_reason' => $reason ?? 'task_terminal',
                    ]),
                ])->save();

                return $locked->refresh();
            });

            if ($event instanceof WorkflowSlaEvent) {
                $cancelled++;
                event(new SlaEventCancelled($event, ['reason' => $reason ?? 'task_terminal']));
            }
        }

        return $cancelled;
    }

    public function cancelIfTaskTerminal(WorkflowTask $task): int
    {
        if ($task->status === WorkflowTaskStatus::Pending) {
            return 0;
        }

        return $this->cancelForTask($task);
    }
}

