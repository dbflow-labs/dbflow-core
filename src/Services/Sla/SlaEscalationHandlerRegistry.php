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

use DbflowLabs\Core\Contracts\Sla\SlaEscalationHandler;
use InvalidArgumentException;

final class SlaEscalationHandlerRegistry
{
    /**
     * @var array<string, SlaEscalationHandler>
     */
    private array $handlers = [];

    public function register(string $key, SlaEscalationHandler $handler): void
    {
        $this->handlers[$key] = $handler;
    }

    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    public function resolve(string $key): SlaEscalationHandler
    {
        if (! isset($this->handlers[$key])) {
            throw new InvalidArgumentException("SLA escalation handler [{$key}] is not registered.");
        }

        return $this->handlers[$key];
    }

    /**
     * @return list<string>
     */
    public function registeredHandlers(): array
    {
        $keys = array_keys($this->handlers);
        sort($keys);

        return $keys;
    }
}

