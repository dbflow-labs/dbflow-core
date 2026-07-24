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

use DbflowLabs\Core\Actions\ReassignTask;
use DbflowLabs\Core\Contracts\Sla\SlaEscalationContext;
use DbflowLabs\Core\Contracts\Sla\SlaHandlerResult;
use DbflowLabs\Core\Contracts\Sla\SlaNotificationContext;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\SlaEscalationType;
use DbflowLabs\Core\Enums\SlaEventStatus;
use DbflowLabs\Core\Enums\SlaEventType;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskBecameOverdue;
use DbflowLabs\Core\Events\TaskEscalated;
use DbflowLabs\Core\Events\TaskEscalationDue;
use DbflowLabs\Core\Events\TaskEscalationFailed;
use DbflowLabs\Core\Events\TaskReminderDue;
use DbflowLabs\Core\Events\TaskReminderFailed;
use DbflowLabs\Core\Exceptions\TaskNotPendingException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowSlaEvent;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Sla\SlaEscalationPolicy;
use DbflowLabs\Core\Sla\SlaPolicy;
use DbflowLabs\Core\Sla\SlaRetryPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessSlaEvent
{
    public function __construct(
        private readonly SlaNotificationHandlerRegistry $notificationHandlers,
        private readonly SlaEscalationHandlerRegistry $escalationHandlers,
        private readonly SlaEscalationTargetResolver $escalationTargetResolver,
        private readonly ReassignTask $reassignTask,
        private readonly CancelTaskSlaEvents $cancelTaskSlaEvents,
        private readonly WorkflowLogger $logger,
    ) {}

    public function handle(int $eventId, int $expectedAttempt): bool
    {
        /** @var WorkflowSlaEvent|null $event */
        $event = WorkflowSlaEvent::query()->find($eventId);

        if ($event === null) {
            return false;
        }

        if ($event->status !== SlaEventStatus::Processing || $event->attempts !== $expectedAttempt) {
            return false;
        }

        $task = WorkflowTask::query()->find($event->workflow_task_id);
        $instance = WorkflowInstance::query()->find($event->workflow_instance_id);

        if ($task === null || $instance === null) {
            $this->markFailed($event, 'Task or instance no longer exists.', retryable: false);

            return false;
        }

        if ($task->status !== WorkflowTaskStatus::Pending) {
            $this->cancelTaskSlaEvents->cancelForTask($task, 'task_terminal_before_processing');

            return false;
        }

        try {
            $result = match ($event->event_type) {
                SlaEventType::Reminder => $this->processReminder($event, $task, $instance),
                SlaEventType::Overdue => $this->processOverdue($event, $task, $instance),
                SlaEventType::Escalation => $this->processEscalation($event, $task, $instance),
            };
        } catch (TaskNotPendingException $exception) {
            $this->cancelTaskSlaEvents->cancelForTask($task->refresh(), 'task_terminal_during_processing');

            return false;
        } catch (Throwable $throwable) {
            $this->markFailed($event, $throwable->getMessage(), retryable: true, task: $task, instance: $instance);

            return false;
        }

        if ($result->isSuccessful()) {
            $this->markCompleted($event, $result);

            return true;
        }

        if ($result->isRetryable()) {
            $this->scheduleRetryOrFail($event, $result->message ?? 'Retryable handler failure.', $task, $instance);

            return false;
        }

        $this->markFailed($event, $result->message ?? 'Handler failed.', retryable: false, task: $task, instance: $instance);

        return false;
    }

    private function processReminder(
        WorkflowSlaEvent $event,
        WorkflowTask $task,
        WorkflowInstance $instance,
    ): SlaHandlerResult {
        event(new TaskReminderDue($event, $task, $instance));

        $channel = (string) ($event->policy_snapshot[WorkflowDefinitionSchema::SLA_CHANNEL]
            ?? config('dbflow.sla.default_notification_channel', 'event'));
        $handler = $this->notificationHandlers->resolve($channel);
        $context = $this->buildNotificationContext($event, $task, $instance, SlaEventType::Reminder, $channel);

        return $handler->handle($context);
    }

    private function processOverdue(
        WorkflowSlaEvent $event,
        WorkflowTask $task,
        WorkflowInstance $instance,
    ): SlaHandlerResult {
        $notifyChannel = DB::transaction(function () use ($event, $task, $instance): ?string {
            /** @var WorkflowTask $lockedTask */
            $lockedTask = WorkflowTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedTask->status !== WorkflowTaskStatus::Pending) {
                $this->cancelTaskSlaEvents->cancelForTask($lockedTask);

                return null;
            }

            if ($lockedTask->overdue_at === null) {
                $lockedTask->forceFill(['overdue_at' => Carbon::now('UTC')])->save();
            }

            $alreadyLogged = WorkflowLog::query()
                ->where('workflow_task_id', $lockedTask->getKey())
                ->where('event', WorkflowLogEvent::TaskBecameOverdue->value)
                ->exists();

            if (! $alreadyLogged) {
                $payload = [
                    'node_key' => $lockedTask->node_key,
                    'due_at' => $lockedTask->due_at?->toIso8601String(),
                    'sla_event_id' => $event->getKey(),
                    'idempotency_key' => $event->idempotency_key,
                ];

                $this->logger->log(
                    $instance,
                    WorkflowLogEvent::TaskBecameOverdue,
                    $lockedTask,
                    null,
                    payload: $payload,
                );

                event(new TaskBecameOverdue($lockedTask->refresh(), $instance->refresh(), $payload));
            }

            $policy = $this->resolveTaskPolicy($lockedTask);

            if ($policy?->overdue?->notify === true) {
                return $policy->overdue->channel
                    ?? (string) config('dbflow.sla.default_notification_channel', 'event');
            }

            return '';
        });

        // Cancelled because task became terminal inside the lock.
        if ($notifyChannel === null) {
            return SlaHandlerResult::successful(['cancelled' => true]);
        }

        // No overdue notification configured — overdue marker alone is enough.
        if ($notifyChannel === '') {
            return SlaHandlerResult::successful();
        }

        // Notification handlers must run outside the claim/marker transaction.
        $freshTask = $task->refresh();
        $handler = $this->notificationHandlers->resolve($notifyChannel);
        $context = $this->buildNotificationContext(
            $event,
            $freshTask,
            $instance->refresh(),
            SlaEventType::Overdue,
            $notifyChannel,
        );

        return $handler->handle($context);
    }

    private function processEscalation(
        WorkflowSlaEvent $event,
        WorkflowTask $task,
        WorkflowInstance $instance,
    ): SlaHandlerResult {
        event(new TaskEscalationDue($event, $task, $instance));

        $policy = SlaEscalationPolicy::fromConfigArray($event->policy_snapshot);

        return match ($policy->type) {
            SlaEscalationType::Notify => $this->processNotifyEscalation($event, $task, $instance, $policy),
            SlaEscalationType::Reassign => $this->processReassignEscalation($event, $task, $instance, $policy),
            SlaEscalationType::Custom => $this->processCustomEscalation($event, $task, $instance, $policy),
        };
    }

    private function processNotifyEscalation(
        WorkflowSlaEvent $event,
        WorkflowTask $task,
        WorkflowInstance $instance,
        SlaEscalationPolicy $policy,
    ): SlaHandlerResult {
        $channel = $policy->channel ?? (string) config('dbflow.sla.default_notification_channel', 'event');
        $handler = $this->notificationHandlers->resolve($channel);
        $context = $this->buildNotificationContext($event, $task, $instance, SlaEventType::Escalation, $channel);

        $result = $handler->handle($context);

        if ($result->isSuccessful()) {
            event(new TaskEscalated($event, $task, $instance, [
                'type' => SlaEscalationType::Notify->value,
                'channel' => $channel,
            ]));
        }

        return $result;
    }

    private function processReassignEscalation(
        WorkflowSlaEvent $event,
        WorkflowTask $task,
        WorkflowInstance $instance,
        SlaEscalationPolicy $policy,
    ): SlaHandlerResult {
        $target = $policy->target ?? [];
        $toUserId = $this->escalationTargetResolver->resolveSingleUserId($instance, $target);

        $this->reassignTask->handleEscalation(
            $task,
            $toUserId,
            $event->idempotency_key,
            'SLA escalation reassignment.',
        );

        event(new TaskEscalated($event, $task->refresh(), $instance->refresh(), [
            'type' => SlaEscalationType::Reassign->value,
            'to_user_id' => $toUserId,
        ]));

        return SlaHandlerResult::successful(['to_user_id' => $toUserId]);
    }

    private function processCustomEscalation(
        WorkflowSlaEvent $event,
        WorkflowTask $task,
        WorkflowInstance $instance,
        SlaEscalationPolicy $policy,
    ): SlaHandlerResult {
        $handlerKey = $policy->handler ?? '';

        if ($handlerKey === '') {
            return SlaHandlerResult::failed('Custom escalation handler key is missing.');
        }

        $handler = $this->escalationHandlers->resolve($handlerKey);
        $actors = $this->collectActors($task);
        $context = new SlaEscalationContext(
            $event,
            $instance,
            $task,
            $task->node_key,
            SlaEscalationType::Custom,
            $task->due_at,
            $event->idempotency_key,
            $actors['original'],
            $actors['effective'],
            $policy->target ?? [],
            $event->policy_snapshot,
            $handlerKey,
        );

        $result = $handler->handle($context);

        if ($result->isSuccessful()) {
            event(new TaskEscalated($event, $task, $instance, [
                'type' => SlaEscalationType::Custom->value,
                'handler' => $handlerKey,
            ]));
        }

        return $result;
    }

    private function buildNotificationContext(
        WorkflowSlaEvent $event,
        WorkflowTask $task,
        WorkflowInstance $instance,
        SlaEventType $eventType,
        string $channel,
    ): SlaNotificationContext {
        $actors = $this->collectActors($task);
        $overdueSeconds = null;

        if ($task->due_at !== null && $eventType !== SlaEventType::Reminder) {
            $now = Carbon::now('UTC');
            $overdueSeconds = $now->greaterThan($task->due_at)
                ? (int) $task->due_at->diffInSeconds($now)
                : 0;
        }

        return new SlaNotificationContext(
            $event,
            $instance,
            $task,
            $task->node_key,
            $eventType,
            $task->due_at,
            $overdueSeconds,
            $channel,
            $event->idempotency_key,
            $actors['original'],
            $actors['effective'],
            $event->policy_snapshot,
        );
    }

    /**
     * @return array{original: list<string>, effective: list<string>}
     */
    private function collectActors(WorkflowTask $task): array
    {
        $assignments = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->get();

        $original = [];
        $effective = [];

        foreach ($assignments as $assignment) {
            $original[] = $assignment->originalAssigneeUserId();
            $effective[] = $assignment->effectiveAssigneeUserId();
        }

        return [
            'original' => array_values(array_unique($original)),
            'effective' => array_values(array_unique($effective)),
        ];
    }

    private function resolveTaskPolicy(WorkflowTask $task): ?SlaPolicy
    {
        $snapshot = $task->sla_policy_snapshot;

        if (! is_array($snapshot) || $snapshot === []) {
            return null;
        }

        return SlaPolicy::fromSnapshotArray($snapshot);
    }

    private function resolveRetryPolicy(WorkflowSlaEvent $event, WorkflowTask $task): SlaRetryPolicy
    {
        unset($event);

        $policy = $this->resolveTaskPolicy($task);

        return $policy !== null ? $policy->retry : SlaRetryPolicy::fromConfigArray([]);
    }

    private function markCompleted(WorkflowSlaEvent $event, SlaHandlerResult $result): void
    {
        DB::transaction(function () use ($event, $result): void {
            /** @var WorkflowSlaEvent $locked */
            $locked = WorkflowSlaEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SlaEventStatus::Processing) {
                return;
            }

            $locked->forceFill([
                'status' => SlaEventStatus::Completed,
                'processed_at' => Carbon::now('UTC'),
                'processing_started_at' => null,
                'result_metadata' => $result->metadata,
                'last_error' => null,
            ])->save();
        });
    }

    private function scheduleRetryOrFail(
        WorkflowSlaEvent $event,
        string $message,
        WorkflowTask $task,
        WorkflowInstance $instance,
    ): void {
        $sanitized = $this->sanitizeError($message);
        $retry = $this->resolveRetryPolicy($event, $task);
        $canRetry = $event->attempts < $event->max_attempts;
        $backoff = $canRetry ? $retry->backoffForAttempt($event->attempts) : null;
        $nextAttempt = ($canRetry && $backoff !== null)
            ? Carbon::now('UTC')->addSeconds($backoff)
            : null;

        DB::transaction(function () use ($event, $sanitized, $canRetry, $nextAttempt): void {
            /** @var WorkflowSlaEvent $locked */
            $locked = WorkflowSlaEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SlaEventStatus::Processing) {
                return;
            }

            if ($canRetry) {
                $locked->forceFill([
                    'status' => SlaEventStatus::Pending,
                    'processing_started_at' => null,
                    'next_attempt_at' => $nextAttempt,
                    'last_error' => $sanitized,
                ])->save();

                return;
            }

            $locked->forceFill([
                'status' => SlaEventStatus::Failed,
                'failed_at' => Carbon::now('UTC'),
                'processing_started_at' => null,
                'last_error' => $sanitized,
            ])->save();
        });

        $this->dispatchFailureEvent($event->refresh(), $task, $instance, $sanitized, retry: $canRetry);
    }

    private function markFailed(
        WorkflowSlaEvent $event,
        string $message,
        bool $retryable,
        ?WorkflowTask $task = null,
        ?WorkflowInstance $instance = null,
    ): void {
        $sanitized = $this->sanitizeError($message);

        if ($retryable && $task instanceof WorkflowTask) {
            $retry = $this->resolveRetryPolicy($event, $task);
            $canRetry = $event->attempts < $event->max_attempts;
            $backoff = $canRetry ? $retry->backoffForAttempt($event->attempts) : null;
            $nextAttempt = ($canRetry && $backoff !== null)
                ? Carbon::now('UTC')->addSeconds($backoff)
                : null;

            DB::transaction(function () use ($event, $sanitized, $canRetry, $nextAttempt): void {
                /** @var WorkflowSlaEvent $locked */
                $locked = WorkflowSlaEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== SlaEventStatus::Processing) {
                    return;
                }

                if ($canRetry) {
                    $locked->forceFill([
                        'status' => SlaEventStatus::Pending,
                        'processing_started_at' => null,
                        'next_attempt_at' => $nextAttempt,
                        'last_error' => $sanitized,
                    ])->save();

                    return;
                }

                $locked->forceFill([
                    'status' => SlaEventStatus::Failed,
                    'failed_at' => Carbon::now('UTC'),
                    'processing_started_at' => null,
                    'last_error' => $sanitized,
                ])->save();
            });

            if ($instance instanceof WorkflowInstance) {
                $this->dispatchFailureEvent($event->refresh(), $task, $instance, $sanitized, retry: $canRetry);
            }

            return;
        }

        DB::transaction(function () use ($event, $sanitized): void {
            /** @var WorkflowSlaEvent $locked */
            $locked = WorkflowSlaEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== SlaEventStatus::Processing) {
                return;
            }

            $locked->forceFill([
                'status' => SlaEventStatus::Failed,
                'failed_at' => Carbon::now('UTC'),
                'processing_started_at' => null,
                'last_error' => $sanitized,
            ])->save();
        });

        if ($task !== null && $instance !== null) {
            $this->dispatchFailureEvent($event->refresh(), $task, $instance, $sanitized, retry: false);
        }
    }

    private function dispatchFailureEvent(
        WorkflowSlaEvent $event,
        WorkflowTask $task,
        WorkflowInstance $instance,
        string $sanitized,
        bool $retry,
    ): void {
        $payload = ['error' => $sanitized, 'retry' => $retry];

        if ($event->event_type === SlaEventType::Reminder) {
            event(new TaskReminderFailed($event, $task, $instance, $payload));

            return;
        }

        if ($event->event_type === SlaEventType::Escalation) {
            event(new TaskEscalationFailed($event, $task, $instance, $payload));
        }
    }

    private function sanitizeError(string $message): string
    {
        $max = (int) config('dbflow.sla.max_error_length', 1000);
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (mb_strlen($message) <= $max) {
            return $message;
        }

        return mb_substr($message, 0, $max);
    }
}
