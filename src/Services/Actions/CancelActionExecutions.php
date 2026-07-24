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
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Events\ActionExecutionCancelled;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CancelActionExecutions
{
    /**
     * Cancel a single queued execution. Running work is never cancelled by this API.
     */
    public function cancelQueuedExecution(WorkflowActionExecution $execution, ?string $reason = null): WorkflowActionExecution
    {
        $now = Carbon::now('UTC');
        $cancelReason = $reason ?? 'manual_cancel';

        $cancelled = DB::transaction(function () use ($execution, $now, $cancelReason): WorkflowActionExecution {
            /** @var WorkflowActionExecution|null $locked */
            $locked = WorkflowActionExecution::query()->whereKey($execution->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new \InvalidArgumentException('Action execution was not found.');
            }

            if ($locked->status !== ActionExecutionStatus::Queued) {
                throw new \InvalidArgumentException('Only queued action executions can be cancelled safely.');
            }

            $locked->forceFill([
                'status' => ActionExecutionStatus::Cancelled,
                'cancelled_at' => $now,
                'processing_started_at' => null,
                'result_metadata' => array_merge($locked->result_metadata ?? [], [
                    'cancel_reason' => $cancelReason,
                ]),
            ])->save();

            return $locked->refresh();
        });

        event(new ActionExecutionCancelled($cancelled, ['reason' => $cancelReason]));

        return $cancelled;
    }

    public function cancelForInstance(WorkflowInstance $instance, ?string $reason = null): int
    {
        $cancelled = 0;
        $now = Carbon::now('UTC');

        $executionIds = WorkflowActionExecution::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->whereIn('status', [
                ActionExecutionStatus::Queued->value,
                ActionExecutionStatus::Running->value,
            ])
            ->pluck('id');

        foreach ($executionIds as $executionId) {
            $execution = DB::transaction(function () use ($executionId, $now, $reason): ?WorkflowActionExecution {
                /** @var WorkflowActionExecution|null $locked */
                $locked = WorkflowActionExecution::query()->whereKey($executionId)->lockForUpdate()->first();

                if ($locked === null) {
                    return null;
                }

                if (in_array($locked->status, [
                    ActionExecutionStatus::Succeeded,
                    ActionExecutionStatus::Failed,
                    ActionExecutionStatus::Exhausted,
                    ActionExecutionStatus::Cancelled,
                    ActionExecutionStatus::Skipped,
                ], true)) {
                    return null;
                }

                $locked->forceFill([
                    'status' => ActionExecutionStatus::Cancelled,
                    'cancelled_at' => $now,
                    'processing_started_at' => null,
                    'result_metadata' => array_merge($locked->result_metadata ?? [], [
                        'cancel_reason' => $reason ?? 'instance_terminal',
                    ]),
                ])->save();

                return $locked->refresh();
            });

            if ($execution instanceof WorkflowActionExecution) {
                $cancelled++;
                event(new ActionExecutionCancelled($execution, ['reason' => $reason ?? 'instance_terminal']));
            }
        }

        return $cancelled;
    }

    public function cancelIfInstanceTerminal(WorkflowInstance $instance): int
    {
        if ($instance->status === WorkflowInstanceStatus::Running) {
            return 0;
        }

        return $this->cancelForInstance($instance);
    }
}
