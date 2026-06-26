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

namespace DbflowLabs\Core\Services;

use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Support\ResolvesActorUserId;

final class WorkflowLogger
{
    use ResolvesActorUserId;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(
        WorkflowInstance $instance,
        WorkflowLogEvent|string $event,
        ?WorkflowTask $task = null,
        mixed $actor = null,
        ?string $comment = null,
        array $payload = [],
    ): WorkflowLog {
        $eventValue = $event instanceof WorkflowLogEvent ? $event->value : (string) $event;

        return WorkflowLog::query()->create([
            'workflow_instance_id' => $instance->getKey(),
            'workflow_task_id' => $task?->getKey(),
            'event' => $eventValue,
            'actor_user_id' => $this->resolveActorUserId($actor),
            'comment' => $comment,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
