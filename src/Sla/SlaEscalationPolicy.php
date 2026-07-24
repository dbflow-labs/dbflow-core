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

namespace DbflowLabs\Core\Sla;

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\SlaEscalationType;
use InvalidArgumentException;

final class SlaEscalationPolicy
{
    /**
     * @param  array<string, mixed>|null  $target
     */
    public function __construct(
        public readonly SlaEscalationType $type,
        public readonly ?string $channel,
        public readonly ?string $handler,
        public readonly ?array $target,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfigArray(array $config): self
    {
        $typeRaw = $config[WorkflowDefinitionSchema::SLA_TYPE] ?? null;

        if (! is_string($typeRaw) || $typeRaw === '') {
            throw new InvalidArgumentException('SLA escalation type is required.');
        }

        $type = SlaEscalationType::tryFrom($typeRaw);

        if ($type === null) {
            throw new InvalidArgumentException("SLA escalation type [{$typeRaw}] is not supported.");
        }

        $channel = $config[WorkflowDefinitionSchema::SLA_CHANNEL] ?? null;
        $channel = is_string($channel) && $channel !== '' ? $channel : null;
        $handler = $config[WorkflowDefinitionSchema::SLA_HANDLER] ?? null;
        $handler = is_string($handler) && $handler !== '' ? $handler : null;
        $target = $config[WorkflowDefinitionSchema::SLA_TARGET] ?? null;
        $target = is_array($target) ? $target : null;

        if ($type === SlaEscalationType::Notify && $channel === null) {
            $channel = (string) config('dbflow.sla.default_notification_channel', 'event');
        }

        if ($type === SlaEscalationType::Reassign && $target === null) {
            throw new InvalidArgumentException('SLA reassign escalation requires a target.');
        }

        if ($type === SlaEscalationType::Custom && $handler === null) {
            throw new InvalidArgumentException('SLA custom escalation requires a handler key.');
        }

        return new self($type, $channel, $handler, $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            WorkflowDefinitionSchema::SLA_TYPE => $this->type->value,
        ];

        if ($this->channel !== null) {
            $payload[WorkflowDefinitionSchema::SLA_CHANNEL] = $this->channel;
        }

        if ($this->handler !== null) {
            $payload[WorkflowDefinitionSchema::SLA_HANDLER] = $this->handler;
        }

        if ($this->target !== null) {
            $payload[WorkflowDefinitionSchema::SLA_TARGET] = $this->target;
        }

        return $payload;
    }
}

