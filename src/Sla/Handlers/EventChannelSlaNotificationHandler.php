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

namespace DbflowLabs\Core\Sla\Handlers;

use DbflowLabs\Core\Contracts\Sla\SlaHandlerResult;
use DbflowLabs\Core\Contracts\Sla\SlaNotificationContext;
use DbflowLabs\Core\Contracts\Sla\SlaNotificationHandler;
use DbflowLabs\Core\Enums\SlaEventType;
use DbflowLabs\Core\Events\TaskReminderDispatched;

/**
 * Built-in event-channel handler.
 *
 * Successful handoff means a Laravel domain event was dispatched to the host
 * application bus. It does not prove email/SMS/third-party delivery.
 */
final class EventChannelSlaNotificationHandler implements SlaNotificationHandler
{
    public function handle(SlaNotificationContext $context): SlaHandlerResult
    {
        // TaskReminderDispatched is reminder-specific. Overdue/escalation notify
        // handoff is recorded via ProcessSlaEvent audit events (TaskBecameOverdue /
        // TaskEscalated) after this handler returns successfully.
        if ($context->eventType === SlaEventType::Reminder) {
            event(new TaskReminderDispatched(
                $context->event,
                $context->task,
                $context->instance,
                [
                    'channel' => $context->channel,
                    'idempotency_key' => $context->idempotencyKey,
                    'event_type' => $context->eventType->value,
                    'node_key' => $context->nodeKey,
                ],
            ));
        }

        return SlaHandlerResult::successful([
            'channel' => $context->channel,
            'dispatched' => true,
            'event_type' => $context->eventType->value,
        ]);
    }
}
