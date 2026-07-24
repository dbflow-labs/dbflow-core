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
use DbflowLabs\Core\Contracts\Sla\SlaNotificationHandler;
use DbflowLabs\Core\Sla\Handlers\EventChannelSlaNotificationHandler;
use InvalidArgumentException;

final class SlaNotificationHandlerRegistry
{
    /**
     * @var array<string, SlaNotificationHandler>
     */
    private array $handlers = [];

    public function __construct()
    {
        $this->register('event', new EventChannelSlaNotificationHandler);
    }

    public function register(string $channel, SlaNotificationHandler $handler): void
    {
        $this->handlers[$channel] = $handler;
    }

    public function has(string $channel): bool
    {
        return isset($this->handlers[$channel]);
    }

    public function resolve(string $channel): SlaNotificationHandler
    {
        if (! isset($this->handlers[$channel])) {
            throw new InvalidArgumentException("SLA notification handler for channel [{$channel}] is not registered.");
        }

        return $this->handlers[$channel];
    }

    /**
     * @return list<string>
     */
    public function registeredChannels(): array
    {
        $keys = array_keys($this->handlers);
        sort($keys);

        return $keys;
    }
}

