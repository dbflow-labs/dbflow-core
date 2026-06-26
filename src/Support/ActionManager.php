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

namespace DbflowLabs\Core\Support;

use DbflowLabs\Core\Contracts\ActionHandler;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class ActionManager
{
    /**
     * @var array<string, class-string<ActionHandler>>
     */
    private array $handlers = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Register an action handler for the given action key.
     *
     * @param  class-string<ActionHandler>  $handlerClass
     *
     * @throws InvalidArgumentException When $handlerClass does not implement ActionHandler
     */
    public function register(string $actionKey, string $handlerClass): void
    {
        if (! is_a($handlerClass, ActionHandler::class, true)) {
            throw new InvalidArgumentException(
                "Action handler [{$handlerClass}] must implement ActionHandler."
            );
        }

        $this->handlers[$actionKey] = $handlerClass;
    }

    public function has(string $actionKey): bool
    {
        return isset($this->handlers[$actionKey]);
    }

    /**
     * Resolve the registered handler via the Laravel container.
     */
    public function resolve(string $actionKey): ?ActionHandler
    {
        if (! isset($this->handlers[$actionKey])) {
            return null;
        }

        return $this->container->make($this->handlers[$actionKey]);
    }

    /**
     * @return list<string>
     */
    public function registeredKeys(): array
    {
        return array_keys($this->handlers);
    }
}
