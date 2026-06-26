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

use DbflowLabs\Core\Contracts\WorkflowDefinitionProvider;

final class WorkflowDefinitionRegistry
{
    /**
     * @var array<string, WorkflowDefinitionProvider>
     */
    private array $providers = [];

    public function register(WorkflowDefinitionProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    /**
     * @return list<WorkflowDefinitionProvider>
     */
    public function providers(): array
    {
        return array_values($this->providers);
    }

    public function provider(string $key): ?WorkflowDefinitionProvider
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->providers as $key => $provider) {
            $definitions[$key] = $provider->definition();
        }

        return $definitions;
    }
}
