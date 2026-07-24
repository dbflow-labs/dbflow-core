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

/**
 * Normalized immutable SLA policy snapshot.
 *
 * @phpstan-type SlaReminderArray array{before_due: string, channel: string, sequence: int, template?: string, metadata?: array<string, mixed>}
 * @phpstan-type SlaEscalationArray array{type: string, channel?: string, handler?: string, target?: array<string, mixed>}
 * @phpstan-type SlaOverdueArray array{notify: bool, channel?: string, escalation?: SlaEscalationArray}
 * @phpstan-type SlaRetryArray array{max_attempts: int, backoff_seconds: list<int>}
 */
final class SlaPolicy
{
    /**
     * @param  list<SlaReminderArray>  $reminders
     */
    public function __construct(
        public readonly string $dueAfter,
        public readonly array $reminders,
        public readonly ?SlaOverduePolicy $overdue,
        public readonly SlaRetryPolicy $retry,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfigArray(array $config): self
    {
        $dueAfter = $config[WorkflowDefinitionSchema::SLA_DUE_AFTER] ?? null;

        if (! is_string($dueAfter) || trim($dueAfter) === '') {
            throw new InvalidArgumentException('SLA due_after is required when SLA is configured.');
        }

        $dueDuration = SlaDuration::parse($dueAfter);
        $reminders = self::parseReminders($config, $dueDuration);
        $overdue = self::parseOverdue($config);
        $retry = SlaRetryPolicy::fromConfigArray(
            is_array($config[WorkflowDefinitionSchema::SLA_RETRY] ?? null)
                ? $config[WorkflowDefinitionSchema::SLA_RETRY]
                : [],
        );

        return new self(
            dueAfter: $dueDuration->normalized(),
            reminders: $reminders,
            overdue: $overdue,
            retry: $retry,
        );
    }

    public function hasEscalation(): bool
    {
        return $this->overdue?->escalation !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshotArray(): array
    {
        $payload = [
            WorkflowDefinitionSchema::SLA_DUE_AFTER => $this->dueAfter,
            WorkflowDefinitionSchema::SLA_REMINDERS => $this->reminders,
            WorkflowDefinitionSchema::SLA_RETRY => $this->retry->toArray(),
        ];

        if ($this->overdue instanceof SlaOverduePolicy) {
            $payload[WorkflowDefinitionSchema::SLA_OVERDUE] = $this->overdue->toArray();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshotArray(array $snapshot): self
    {
        return self::fromConfigArray($snapshot);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<SlaReminderArray>
     */
    private static function parseReminders(array $config, SlaDuration $dueDuration): array
    {
        $raw = $config[WorkflowDefinitionSchema::SLA_REMINDERS] ?? [];

        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException('SLA reminders must be an array.');
        }

        $maxCount = (int) config('dbflow.sla.max_reminder_count', 10);

        if (count($raw) > $maxCount) {
            throw new InvalidArgumentException("SLA reminders must not exceed {$maxCount} entries.");
        }

        $parsed = [];
        $seen = [];

        foreach ($raw as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Each SLA reminder must be an object.');
            }

            $beforeDue = $item[WorkflowDefinitionSchema::SLA_BEFORE_DUE] ?? null;
            $channel = $item[WorkflowDefinitionSchema::SLA_CHANNEL] ?? null;

            if (! is_string($beforeDue) || trim($beforeDue) === '') {
                throw new InvalidArgumentException('SLA reminder before_due is required.');
            }

            if (! is_string($channel) || trim($channel) === '') {
                throw new InvalidArgumentException('SLA reminder channel is required.');
            }

            self::assertValidChannelKey($channel);

            $offset = SlaDuration::parse($beforeDue);

            if ($offset->totalSeconds() >= $dueDuration->totalSeconds()) {
                throw new InvalidArgumentException('SLA reminder before_due must be strictly less than due_after.');
            }

            $dedupeKey = $channel.'|'.$offset->normalized();

            if (isset($seen[$dedupeKey])) {
                throw new InvalidArgumentException('Duplicate SLA reminder offset for the same channel is not allowed.');
            }

            $seen[$dedupeKey] = true;

            $sequence = isset($item[WorkflowDefinitionSchema::SLA_SEQUENCE]) && is_int($item[WorkflowDefinitionSchema::SLA_SEQUENCE])
                ? $item[WorkflowDefinitionSchema::SLA_SEQUENCE]
                : $index + 1;

            $reminder = [
                WorkflowDefinitionSchema::SLA_BEFORE_DUE => $offset->normalized(),
                WorkflowDefinitionSchema::SLA_CHANNEL => $channel,
                WorkflowDefinitionSchema::SLA_SEQUENCE => $sequence,
            ];

            if (isset($item[WorkflowDefinitionSchema::SLA_TEMPLATE]) && is_string($item[WorkflowDefinitionSchema::SLA_TEMPLATE])) {
                $reminder[WorkflowDefinitionSchema::SLA_TEMPLATE] = $item[WorkflowDefinitionSchema::SLA_TEMPLATE];
            }

            if (isset($item[WorkflowDefinitionSchema::SLA_METADATA]) && is_array($item[WorkflowDefinitionSchema::SLA_METADATA])) {
                $reminder[WorkflowDefinitionSchema::SLA_METADATA] = $item[WorkflowDefinitionSchema::SLA_METADATA];
            }

            $parsed[] = $reminder;
        }

        usort($parsed, static fn (array $a, array $b): int => $a[WorkflowDefinitionSchema::SLA_SEQUENCE] <=> $b[WorkflowDefinitionSchema::SLA_SEQUENCE]);

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function parseOverdue(array $config): ?SlaOverduePolicy
    {
        $raw = $config[WorkflowDefinitionSchema::SLA_OVERDUE] ?? null;

        if ($raw === null || $raw === []) {
            return null;
        }

        if (! is_array($raw)) {
            throw new InvalidArgumentException('SLA overdue must be an object when provided.');
        }

        return SlaOverduePolicy::fromConfigArray($raw);
    }

    private static function assertValidChannelKey(string $channel): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $channel) !== 1) {
            throw new InvalidArgumentException('SLA channel key must match [a-z][a-z0-9_]{0,63}.');
        }

        $allowed = config('dbflow.sla.allowed_notification_channels', ['event']);

        if (is_array($allowed) && $allowed !== [] && ! in_array($channel, $allowed, true)) {
            throw new InvalidArgumentException("SLA notification channel [{$channel}] is not allowed.");
        }
    }
}

