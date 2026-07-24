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

final class SlaOverduePolicy
{
    public function __construct(
        public readonly bool $notify,
        public readonly ?string $channel,
        public readonly ?SlaEscalationPolicy $escalation,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfigArray(array $config): self
    {
        $notify = $config[WorkflowDefinitionSchema::SLA_NOTIFY] ?? false;
        $notify = $notify === true;

        $channel = $config[WorkflowDefinitionSchema::SLA_CHANNEL] ?? null;
        $channel = is_string($channel) && $channel !== '' ? $channel : null;

        if ($notify && $channel === null) {
            $channel = (string) config('dbflow.sla.default_notification_channel', 'event');
        }

        $escalationRaw = $config[WorkflowDefinitionSchema::SLA_ESCALATION] ?? null;
        $escalation = is_array($escalationRaw) ? SlaEscalationPolicy::fromConfigArray($escalationRaw) : null;

        self::rejectForbiddenAutoActions($config);

        return new self($notify, $channel, $escalation);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            WorkflowDefinitionSchema::SLA_NOTIFY => $this->notify,
        ];

        if ($this->channel !== null) {
            $payload[WorkflowDefinitionSchema::SLA_CHANNEL] = $this->channel;
        }

        if ($this->escalation instanceof SlaEscalationPolicy) {
            $payload[WorkflowDefinitionSchema::SLA_ESCALATION] = $this->escalation->toArray();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function rejectForbiddenAutoActions(array $config): void
    {
        foreach (['approve', 'auto_approve', 'reject', 'auto_reject'] as $forbidden) {
            if (array_key_exists($forbidden, $config) && $config[$forbidden] === true) {
                throw new InvalidArgumentException("SLA overdue automatic action [{$forbidden}] is not supported.");
            }
        }

        $escalation = $config[WorkflowDefinitionSchema::SLA_ESCALATION] ?? null;

        if (! is_array($escalation)) {
            return;
        }

        $type = $escalation[WorkflowDefinitionSchema::SLA_TYPE] ?? null;

        if (in_array($type, ['approve', 'auto_approve', 'reject', 'auto_reject', 'reject_end'], true)) {
            throw new InvalidArgumentException("SLA escalation type [{$type}] is not supported.");
        }
    }
}

