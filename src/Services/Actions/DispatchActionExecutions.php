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
use DbflowLabs\Core\Jobs\ProcessActionExecutionJob;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class DispatchActionExecutions
{
    /**
     * @return array{claimed: int, dispatched: int}
     */
    public function handle(?Carbon $asOf = null, ?int $limit = null, bool $dryRun = false): array
    {
        $asOf ??= Carbon::now('UTC');
        $limit ??= (int) config('dbflow.actions.dispatch_batch_size', 100);
        $claimed = 0;
        $dispatched = 0;

        $candidateIds = WorkflowActionExecution::query()
            ->where('status', ActionExecutionStatus::Queued)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->where(function ($builder) use ($asOf): void {
                $builder->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $asOf);
            })
            ->whereNull('cancelled_at')
            ->orderBy('queued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($candidateIds as $executionId) {
            $execution = $this->claimExecution((int) $executionId, $asOf, $dryRun);

            if ($execution === null) {
                continue;
            }

            $claimed++;

            if ($dryRun) {
                continue;
            }

            ProcessActionExecutionJob::dispatch((int) $execution->getKey(), (int) $execution->attempts);
            $dispatched++;
        }

        return ['claimed' => $claimed, 'dispatched' => $dispatched];
    }

    public function dispatchExecution(int $executionId, ?Carbon $asOf = null): bool
    {
        $asOf ??= Carbon::now('UTC');
        $execution = $this->claimExecution($executionId, $asOf, dryRun: false);

        if ($execution === null) {
            return false;
        }

        ProcessActionExecutionJob::dispatch((int) $execution->getKey(), (int) $execution->attempts);

        return true;
    }

    public function queueAfterCommit(int $executionId): void
    {
        DB::afterCommit(function () use ($executionId): void {
            $this->dispatchExecution($executionId);
        });
    }

    private function claimExecution(int $executionId, Carbon $asOf, bool $dryRun): ?WorkflowActionExecution
    {
        return DB::transaction(function () use ($executionId, $asOf, $dryRun): ?WorkflowActionExecution {
            /** @var WorkflowActionExecution|null $execution */
            $execution = WorkflowActionExecution::query()->whereKey($executionId)->lockForUpdate()->first();

            if ($execution === null || ! $execution->isClaimable($asOf)) {
                return null;
            }

            if ($dryRun) {
                return $execution;
            }

            $execution->forceFill([
                'status' => ActionExecutionStatus::Running,
                'processing_started_at' => $asOf,
                'attempts' => $execution->attempts + 1,
                'started_at' => $execution->started_at ?? $asOf,
                'next_attempt_at' => null,
            ])->save();

            return $execution->refresh();
        });
    }
}
