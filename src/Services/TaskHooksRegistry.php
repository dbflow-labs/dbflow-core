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

namespace DbflowLabs\Core\Services;

use DbflowLabs\Core\Contracts\TaskHooks;
use Illuminate\Contracts\Container\Container;

final class TaskHooksRegistry
{
    /**
     * @var array<string, TaskHooks|class-string<TaskHooks>>
     */
    private array $hooks = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $workflowKey, TaskHooks|string $hooks): void
    {
        $this->hooks[$workflowKey] = $hooks;
    }

    public function resolve(string $workflowKey): TaskHooks
    {
        if (! isset($this->hooks[$workflowKey])) {
            return new NullTaskHooks;
        }

        $hooks = $this->hooks[$workflowKey];

        if ($hooks instanceof TaskHooks) {
            return $hooks;
        }

        $resolved = $this->container->make($hooks);

        if (! $resolved instanceof TaskHooks) {
            return new NullTaskHooks;
        }

        return $resolved;
    }

    public function has(string $workflowKey): bool
    {
        return isset($this->hooks[$workflowKey]);
    }
}
