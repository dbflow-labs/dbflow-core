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

namespace DbflowLabs\Core\Actions;

use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Enums\RejectStrategy;
use DbflowLabs\Core\Enums\TimeoutOnTimeout;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskTimedOut;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ProcessTaskTimeouts
{
    public function __construct(
        private readonly RejectTask $rejectTask,
        private readonly WorkflowLogger $logger,
    ) {}

    public function handle(?Carbon $asOf = null): int
    {
        DbflowRuntime::ensureEnabled();

        $asOf ??= now();
        $processed = 0;

        $taskIds = WorkflowTask::query()
            ->where('status', WorkflowTaskStatus::Pending)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $asOf)
            ->orderBy('id')
            ->pluck('id');

        foreach ($taskIds as $taskId) {
            if ($this->processTask((int) $taskId, $asOf)) {
                $processed++;
            }
        }

        return $processed;
    }

    private function processTask(int $taskId, Carbon $asOf): bool
    {
        return (bool) DB::transaction(function () use ($taskId, $asOf): bool {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()->whereKey($taskId)->lockForUpdate()->first();

            if ($task === null
                || $task->status !== WorkflowTaskStatus::Pending
                || $task->due_at === null
                || $task->due_at->gt($asOf)) {
                return false;
            }

            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()
                ->whereKey($task->workflow_instance_id)
                ->lockForUpdate()
                ->firstOrFail();

            $node = $instance->blueprint()->findNode($task->node_key);

            if (! $node instanceof ApprovalNode || ! $node->hasTimeout()) {
                return false;
            }

            $alreadyLogged = WorkflowLog::query()
                ->where('workflow_task_id', $task->getKey())
                ->where('event', WorkflowLogEvent::TaskTimedOut->value)
                ->exists();

            if (! $alreadyLogged) {
                $payload = [
                    'node_key' => $task->node_key,
                    'due_at' => $task->due_at->toIso8601String(),
                    'due_in' => $node->timeoutDueIn(),
                    'on_timeout' => $node->timeoutOnTimeout()?->value,
                ];

                $this->logger->log(
                    $instance,
                    WorkflowLogEvent::TaskTimedOut,
                    $task,
                    null,
                    payload: $payload,
                );

                event(new TaskTimedOut($task->fresh() ?? $task, $instance->refresh(), $payload));
            }

            if ($node->timeoutOnTimeout() === TimeoutOnTimeout::RejectEnd) {
                $task = $task->fresh() ?? $task;

                if ($task->status === WorkflowTaskStatus::Pending) {
                    $this->rejectTask->handle(
                        $task,
                        null,
                        'Task timed out.',
                        RejectStrategy::End,
                    );
                }
            }

            return ! $alreadyLogged || $node->timeoutOnTimeout() === TimeoutOnTimeout::RejectEnd;
        });
    }
}
