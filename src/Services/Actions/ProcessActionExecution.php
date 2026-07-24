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
use DbflowLabs\Core\Contracts\Actions\ReliableActionContext;
use DbflowLabs\Core\Contracts\Actions\ReliableActionResult;
use DbflowLabs\Core\Contracts\WorkflowContextInterface;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Enums\ActionExecutionStatus;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Events\ActionExecutionExhausted;
use DbflowLabs\Core\Events\ActionExecutionFailed;
use DbflowLabs\Core\Events\ActionExecutionRetryScheduled;
use DbflowLabs\Core\Events\ActionExecutionStarted;
use DbflowLabs\Core\Events\ActionExecutionSucceeded;
use DbflowLabs\Core\Models\WorkflowActionAttempt;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\WorkflowLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class ProcessActionExecution
{
    public function __construct(
        private readonly ReliableActionHandlerRegistry $handlerRegistry,
        private readonly AdvanceWorkflowFromAction $advanceWorkflowFromAction,
        private readonly CancelActionExecutions $cancelActionExecutions,
        private readonly WorkflowLogger $logger,
    ) {}

    public function handle(int $executionId, int $expectedAttempt): bool
    {
        /** @var WorkflowActionExecution|null $execution */
        $execution = WorkflowActionExecution::query()->find($executionId);

        if ($execution === null) {
            return false;
        }

        if ($execution->status !== ActionExecutionStatus::Running || $execution->attempts !== $expectedAttempt) {
            return false;
        }

        $instance = WorkflowInstance::query()->find($execution->workflow_instance_id);

        if ($instance === null) {
            $this->markPermanentFailure($execution, 'Workflow instance no longer exists.');

            return false;
        }

        if ($instance->status === WorkflowInstanceStatus::Cancelled) {
            $this->cancelActionExecutions->cancelForInstance($instance, 'instance_cancelled_before_processing');

            return false;
        }

        if ($instance->status !== WorkflowInstanceStatus::Running
            && $execution->execution_mode !== ActionExecutionMode::ReliableNonBlocking) {
            $this->cancelActionExecutions->cancelForInstance($instance, 'instance_terminal_before_processing');

            return false;
        }

        $task = $execution->workflow_task_id !== null
            ? WorkflowTask::query()->find($execution->workflow_task_id)
            : null;

        event(new ActionExecutionStarted($execution, $instance, $task));

        $attempt = $this->openAttempt($execution, $expectedAttempt);

        try {
            $node = $this->hydrateNode($execution);
            $variables = $this->resolveVariables($instance);
            $handler = $this->handlerRegistry->resolve($execution->action_key);
            $context = new ReliableActionContext(
                $execution,
                $instance,
                $task,
                $node,
                $execution->action_key,
                $execution->execution_mode,
                $execution->logical_execution_key,
                $expectedAttempt,
                is_array($execution->payload_snapshot) ? $execution->payload_snapshot : [],
                $variables,
            );

            // Handlers must run outside claim/update transactions (external I/O).
            $result = $handler->handle($context);
        } catch (InvalidArgumentException $exception) {
            $result = ReliableActionResult::failed($exception->getMessage());
        } catch (Throwable $throwable) {
            $result = ReliableActionResult::retryable($throwable->getMessage());
        }

        $this->closeAttempt($attempt, $result);

        // Re-check after external I/O: cancellation/skip during handler must win.
        $execution = $execution->refresh();

        if ($execution->status !== ActionExecutionStatus::Running || $execution->attempts !== $expectedAttempt) {
            return false;
        }

        if ($result->isSuccessful()) {
            $this->markSucceeded($execution, $result, $instance, $task);

            return true;
        }

        if ($result->isRetryable()) {
            $this->scheduleRetryOrExhaust($execution, $result, $instance, $task);

            return false;
        }

        $this->markPermanentFailure($execution, $result->message ?? 'Handler failed.', $result, $instance, $task);

        return false;
    }

    private function hydrateNode(WorkflowActionExecution $execution): ActionNode
    {
        $snapshot = $execution->node_snapshot;

        if (! is_array($snapshot) || $snapshot === []) {
            throw new \RuntimeException('Action execution node snapshot is missing.');
        }

        return ActionNode::fromArray($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveVariables(WorkflowInstance $instance): array
    {
        $workflowable = $instance->workflowable;
        $metadata = is_array($instance->metadata) ? $instance->metadata : [];

        if ($workflowable instanceof WorkflowContextInterface) {
            return $workflowable->getWorkflowVariables();
        }

        return is_array($metadata['variables'] ?? null) ? $metadata['variables'] : [];
    }

    private function openAttempt(WorkflowActionExecution $execution, int $attemptNumber): WorkflowActionAttempt
    {
        return WorkflowActionAttempt::query()->firstOrCreate(
            [
                'workflow_action_execution_id' => $execution->getKey(),
                'attempt_number' => $attemptNumber,
            ],
            [
                'status' => ActionExecutionStatus::Running->value,
                'started_at' => Carbon::now('UTC'),
            ],
        );
    }

    private function closeAttempt(WorkflowActionAttempt $attempt, ReliableActionResult $result): void
    {
        $attempt->forceFill([
            'status' => $result->isSuccessful()
                ? ActionExecutionStatus::Succeeded->value
                : ActionExecutionStatus::Failed->value,
            'completed_at' => Carbon::now('UTC'),
            'last_error' => $result->isSuccessful() ? null : $this->sanitizeError($result->message ?? 'Handler failed.'),
            'response_metadata' => $result->metadata,
        ])->save();
    }

    private function markSucceeded(
        WorkflowActionExecution $execution,
        ReliableActionResult $result,
        WorkflowInstance $instance,
        ?WorkflowTask $task,
    ): void {
        $now = Carbon::now('UTC');

        DB::transaction(function () use ($execution, $result, $now): void {
            /** @var WorkflowActionExecution $locked */
            $locked = WorkflowActionExecution::query()->whereKey($execution->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ActionExecutionStatus::Running) {
                return;
            }

            $locked->forceFill([
                'status' => ActionExecutionStatus::Succeeded,
                'succeeded_at' => $now,
                'processing_started_at' => null,
                'last_error' => null,
                'result_metadata' => $result->metadata,
                'response_status' => $result->responseStatus,
            ])->save();
        });

        $execution = $execution->refresh();

        $this->logger->log(
            $instance,
            WorkflowLogEvent::ActionExecutionSucceeded,
            $task,
            payload: [
                'execution_id' => $execution->getKey(),
                'logical_execution_key' => $execution->logical_execution_key,
                'node_key' => $execution->node_key,
                'action_key' => $execution->action_key,
            ],
        );

        event(new ActionExecutionSucceeded($execution, $instance, $task, $result->metadata));

        if ($execution->isBlocking()) {
            $this->advanceWorkflowFromAction->handle($execution, $instance, $task);
        }
    }

    private function scheduleRetryOrExhaust(
        WorkflowActionExecution $execution,
        ReliableActionResult $result,
        WorkflowInstance $instance,
        ?WorkflowTask $task,
    ): void {
        $node = $this->hydrateNode($execution);
        $retryPolicy = ActionRetryPolicy::fromActionNode($node);
        $sanitized = $this->sanitizeError($result->message ?? 'Retryable handler failure.');
        $canRetry = $execution->attempts < $execution->max_attempts;
        $nextAttempt = $canRetry
            ? Carbon::now('UTC')->addSeconds($retryPolicy->backoffForAttempt($execution->attempts))
            : null;

        DB::transaction(function () use ($execution, $sanitized, $canRetry, $nextAttempt, $result): void {
            /** @var WorkflowActionExecution $locked */
            $locked = WorkflowActionExecution::query()->whereKey($execution->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ActionExecutionStatus::Running) {
                return;
            }

            if ($canRetry) {
                $locked->forceFill([
                    'status' => ActionExecutionStatus::Queued,
                    'processing_started_at' => null,
                    'next_attempt_at' => $nextAttempt,
                    'last_error' => $sanitized,
                    'result_metadata' => $result->metadata,
                    'response_status' => $result->responseStatus,
                ])->save();

                return;
            }

            $locked->forceFill([
                'status' => ActionExecutionStatus::Exhausted,
                'exhausted_at' => Carbon::now('UTC'),
                'processing_started_at' => null,
                'last_error' => $sanitized,
                'result_metadata' => $result->metadata,
                'response_status' => $result->responseStatus,
            ])->save();
        });

        $execution = $execution->refresh();

        if ($canRetry) {
            event(new ActionExecutionRetryScheduled($execution, $instance, $task, [
                'error' => $sanitized,
                'next_attempt_at' => $nextAttempt?->toIso8601String(),
            ]));

            return;
        }

        $this->logger->log(
            $instance,
            WorkflowLogEvent::ActionExecutionExhausted,
            $task,
            payload: [
                'execution_id' => $execution->getKey(),
                'logical_execution_key' => $execution->logical_execution_key,
                'error' => $sanitized,
            ],
        );

        event(new ActionExecutionExhausted($execution, $instance, $task, ['error' => $sanitized]));
    }

    private function markPermanentFailure(
        WorkflowActionExecution $execution,
        string $message,
        ?ReliableActionResult $result = null,
        ?WorkflowInstance $instance = null,
        ?WorkflowTask $task = null,
    ): void {
        $sanitized = $this->sanitizeError($message);
        $now = Carbon::now('UTC');

        DB::transaction(function () use ($execution, $sanitized, $result, $now): void {
            /** @var WorkflowActionExecution $locked */
            $locked = WorkflowActionExecution::query()->whereKey($execution->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== ActionExecutionStatus::Running && $locked->status !== ActionExecutionStatus::Queued) {
                return;
            }

            $locked->forceFill([
                'status' => ActionExecutionStatus::Failed,
                'failed_at' => $now,
                'processing_started_at' => null,
                'last_error' => $sanitized,
                'result_metadata' => $result?->metadata,
                'response_status' => $result?->responseStatus,
            ])->save();
        });

        $execution = $execution->refresh();

        if ($instance instanceof WorkflowInstance) {
            $this->logger->log(
                $instance,
                WorkflowLogEvent::ActionExecutionFailed,
                $task,
                payload: [
                    'execution_id' => $execution->getKey(),
                    'logical_execution_key' => $execution->logical_execution_key,
                    'error' => $sanitized,
                ],
            );

            event(new ActionExecutionFailed($execution, $instance, $task, ['error' => $sanitized, 'retry' => false]));
        }
    }

    private function sanitizeError(string $message): string
    {
        $max = (int) config('dbflow.actions.max_error_length', 1000);
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (mb_strlen($message) <= $max) {
            return $message;
        }

        return mb_substr($message, 0, $max);
    }
}
