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
use InvalidArgumentException;

final class SlaRetryPolicy
{
    /**
     * @param  list<int>  $backoffSeconds
     */
    public function __construct(
        public readonly int $maxAttempts,
        public readonly array $backoffSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfigArray(array $config): self
    {
        $configuredMax = (int) config('dbflow.sla.max_attempts', 3);
        $maxAttempts = isset($config[WorkflowDefinitionSchema::SLA_MAX_ATTEMPTS])
            ? (int) $config[WorkflowDefinitionSchema::SLA_MAX_ATTEMPTS]
            : $configuredMax;

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('SLA retry max_attempts must be at least 1.');
        }

        if ($maxAttempts > $configuredMax) {
            throw new InvalidArgumentException("SLA retry max_attempts must not exceed {$configuredMax}.");
        }

        $backoff = $config[WorkflowDefinitionSchema::SLA_BACKOFF_SECONDS] ?? config('dbflow.sla.default_backoff_seconds', [60, 300, 900]);

        if (! is_array($backoff) || $backoff === []) {
            throw new InvalidArgumentException('SLA retry backoff_seconds must be a non-empty array.');
        }

        $normalized = [];

        foreach ($backoff as $seconds) {
            if (! is_int($seconds) || $seconds < 1) {
                throw new InvalidArgumentException('SLA retry backoff_seconds entries must be positive integers.');
            }

            $maxBackoff = (int) config('dbflow.sla.max_backoff_seconds', 86400);

            if ($seconds > $maxBackoff) {
                throw new InvalidArgumentException("SLA retry backoff_seconds must not exceed {$maxBackoff}.");
            }

            $normalized[] = $seconds;
        }

        return new self($maxAttempts, $normalized);
    }

    public function backoffForAttempt(int $attempt): ?int
    {
        if ($attempt < 1) {
            return null;
        }

        $index = $attempt - 1;

        if (! isset($this->backoffSeconds[$index])) {
            return end($this->backoffSeconds) ?: null;
        }

        return $this->backoffSeconds[$index];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            WorkflowDefinitionSchema::SLA_MAX_ATTEMPTS => $this->maxAttempts,
            WorkflowDefinitionSchema::SLA_BACKOFF_SECONDS => $this->backoffSeconds,
        ];
    }
}

