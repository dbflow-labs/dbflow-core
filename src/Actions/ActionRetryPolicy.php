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

namespace DbflowLabs\Core\Actions;

use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use InvalidArgumentException;

final class ActionRetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts,
        public readonly int $backoffSeconds,
    ) {}

    public static function fromActionNode(ActionNode $node): self
    {
        $configuredMax = (int) config('dbflow.actions.max_attempts', 3);
        $retry = $node->retry() ?? [];
        $nodeMax = $node->maxAttempts();

        $maxAttempts = $nodeMax ?? (isset($retry['max_attempts']) ? (int) $retry['max_attempts'] : $configuredMax);

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Action max_attempts must be at least 1.');
        }

        if ($maxAttempts > $configuredMax) {
            throw new InvalidArgumentException("Action max_attempts must not exceed {$configuredMax}.");
        }

        $backoff = isset($retry['backoff_seconds'])
            ? (int) $retry['backoff_seconds']
            : (int) config('dbflow.actions.default_backoff_seconds', 60);

        if ($backoff < 0) {
            throw new InvalidArgumentException('Action retry.backoff_seconds must be non-negative.');
        }

        $maxBackoff = (int) config('dbflow.actions.max_backoff_seconds', 86400);

        if ($backoff > $maxBackoff) {
            throw new InvalidArgumentException("Action retry.backoff_seconds must not exceed {$maxBackoff}.");
        }

        return new self($maxAttempts, $backoff);
    }

    /**
     * @param  array<string, mixed>  $retryConfig
     */
    public static function fromConfigArray(array $retryConfig, ?int $nodeMaxAttempts = null): self
    {
        $configuredMax = (int) config('dbflow.actions.max_attempts', 3);
        $maxAttempts = $nodeMaxAttempts
            ?? (isset($retryConfig[WorkflowDefinitionSchema::CONFIG_MAX_ATTEMPTS])
                ? (int) $retryConfig[WorkflowDefinitionSchema::CONFIG_MAX_ATTEMPTS]
                : $configuredMax);

        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Action max_attempts must be at least 1.');
        }

        $backoff = isset($retryConfig['backoff_seconds'])
            ? (int) $retryConfig['backoff_seconds']
            : (int) config('dbflow.actions.default_backoff_seconds', 60);

        return new self($maxAttempts, max(0, $backoff));
    }

    public function backoffForAttempt(int $attempt): int
    {
        if ($attempt < 1) {
            return $this->backoffSeconds;
        }

        return $this->backoffSeconds;
    }
}
