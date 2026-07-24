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

namespace DbflowLabs\Core\Contracts\Sla;

use DbflowLabs\Core\Enums\SlaEventType;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowSlaEvent;
use DbflowLabs\Core\Models\WorkflowTask;
use Illuminate\Support\Carbon;

/**
 * Immutable notification context passed to SLA notification handlers.
 */
final class SlaNotificationContext
{
    /**
     * @param  list<string>  $originalResponsibleActors
     * @param  list<string>  $effectiveActors
     * @param  array<string, mixed>  $policyMetadata
     */
    public function __construct(
        public readonly WorkflowSlaEvent $event,
        public readonly WorkflowInstance $instance,
        public readonly WorkflowTask $task,
        public readonly string $nodeKey,
        public readonly SlaEventType $eventType,
        public readonly ?Carbon $dueAt,
        public readonly ?int $overdueSeconds,
        public readonly string $channel,
        public readonly string $idempotencyKey,
        public readonly array $originalResponsibleActors,
        public readonly array $effectiveActors,
        public readonly array $policyMetadata,
    ) {}
}

