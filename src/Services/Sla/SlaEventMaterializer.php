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

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\SlaEventStatus;
use DbflowLabs\Core\Enums\SlaEventType;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskSlaScheduled;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowSlaEvent;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Sla\SlaDuration;
use DbflowLabs\Core\Sla\SlaPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

final class SlaEventMaterializer
{
    /**
     * @return list<WorkflowSlaEvent>
     */
    public function materialize(
        WorkflowTask $task,
        WorkflowInstance $instance,
        SlaPolicy $policy,
        Carbon $referenceTime,
    ): array {
        if ($task->status !== WorkflowTaskStatus::Pending) {
            return [];
        }

        $dueAt = SlaDuration::parse($policy->dueAfter)->addTo($referenceTime);
        $created = [];
        $maxAttempts = $policy->retry->maxAttempts;

        foreach ($policy->reminders as $reminder) {
            $beforeDue = SlaDuration::parse($reminder[WorkflowDefinitionSchema::SLA_BEFORE_DUE]);
            $sequence = (int) $reminder[WorkflowDefinitionSchema::SLA_SEQUENCE];
            $scheduledAt = $beforeDue->subtractFrom($dueAt);
            $idempotencyKey = "task:{$task->getKey()}:reminder:{$sequence}";

            $created[] = $this->createEvent(
                $task,
                $instance,
                SlaEventType::Reminder,
                $sequence,
                $scheduledAt,
                $idempotencyKey,
                $maxAttempts,
                $reminder,
            );
        }

        $created[] = $this->createEvent(
            $task,
            $instance,
            SlaEventType::Overdue,
            1,
            $dueAt,
            "task:{$task->getKey()}:overdue",
            $maxAttempts,
            [WorkflowDefinitionSchema::SLA_TYPE => SlaEventType::Overdue->value],
        );

        if ($policy->hasEscalation()) {
            $escalation = $policy->overdue?->escalation;

            if ($escalation !== null) {
                $created[] = $this->createEvent(
                    $task,
                    $instance,
                    SlaEventType::Escalation,
                    1,
                    $dueAt,
                    "task:{$task->getKey()}:escalation:1",
                    $maxAttempts,
                    $escalation->toArray(),
                );
            }
        }

        foreach ($created as $event) {
            event(new TaskSlaScheduled($event, $task, $instance, [
                'idempotency_key' => $event->idempotency_key,
                'event_type' => $event->event_type->value,
                'scheduled_at' => $event->scheduled_at?->toIso8601String(),
            ]));
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $policySnapshot
     */
    private function createEvent(
        WorkflowTask $task,
        WorkflowInstance $instance,
        SlaEventType $type,
        int $sequence,
        Carbon $scheduledAt,
        string $idempotencyKey,
        int $maxAttempts,
        array $policySnapshot,
    ): WorkflowSlaEvent {
        try {
            return WorkflowSlaEvent::query()->create([
                'workflow_task_id' => $task->getKey(),
                'workflow_instance_id' => $instance->getKey(),
                'node_key' => $task->node_key,
                'event_type' => $type,
                'sequence' => $sequence,
                'scheduled_at' => $scheduledAt->utc(),
                'status' => SlaEventStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'policy_snapshot' => $policySnapshot,
            ]);
        } catch (QueryException $exception) {
            $existing = WorkflowSlaEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof WorkflowSlaEvent) {
                return $existing;
            }

            throw $exception;
        }
    }
}

