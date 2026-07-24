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

namespace DbflowLabs\Core\Services\Actions;

use DbflowLabs\Core\Contracts\Actions\ReliableActionHandler;
use InvalidArgumentException;

final class ReliableActionHandlerRegistry
{
    /**
     * @var array<string, ReliableActionHandler>
     */
    private array $handlers = [];

    public function register(string $actionKey, ReliableActionHandler $handler): void
    {
        $this->handlers[$actionKey] = $handler;
    }

    public function has(string $actionKey): bool
    {
        return isset($this->handlers[$actionKey]);
    }

    public function resolve(string $actionKey): ReliableActionHandler
    {
        if (! isset($this->handlers[$actionKey])) {
            throw new InvalidArgumentException("Reliable action handler for key [{$actionKey}] is not registered.");
        }

        return $this->handlers[$actionKey];
    }

    /**
     * @return list<string>
     */
    public function registeredActionKeys(): array
    {
        $keys = array_keys($this->handlers);
        sort($keys);

        return $keys;
    }
}
