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

namespace DbflowLabs\Core\Tests\Feature;

use DbflowLabs\Core\Contracts\UserResolver;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Support\ActionManager;
use DbflowLabs\Core\Support\ConfigUserResolver;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DBFlowServiceProviderTest extends TestCase
{
    #[Test]
    public function service_provider_registers_core_bindings(): void
    {
        $this->assertInstanceOf(UserResolver::class, $this->app->make(UserResolver::class));
        $this->assertInstanceOf(ConfigUserResolver::class, $this->app->make(UserResolver::class));
        $this->assertInstanceOf(WorkflowDefinitionRegistry::class, $this->app->make(WorkflowDefinitionRegistry::class));
        $this->assertInstanceOf(AssigneeResolverRegistry::class, $this->app->make(AssigneeResolverRegistry::class));
        $this->assertInstanceOf(WorkflowHooksRegistry::class, $this->app->make(WorkflowHooksRegistry::class));
        $this->assertInstanceOf(ActionManager::class, $this->app->make(ActionManager::class));
    }

    #[Test]
    public function core_registries_are_singletons(): void
    {
        $this->assertSame(
            $this->app->make(WorkflowDefinitionRegistry::class),
            $this->app->make(WorkflowDefinitionRegistry::class),
        );

        $this->assertSame(
            $this->app->make(ActionManager::class),
            $this->app->make(ActionManager::class),
        );
    }
}
