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

use DbflowLabs\Core\Actions\StartWorkflow;
use DbflowLabs\Core\Contracts\UserResolver;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Exceptions\WorkflowNotAvailableException;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Support\DbflowRuntime;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DisabledDbflowTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('dbflow.enabled', false);
    }

    #[Test]
    public function disabled_config_skips_service_provider_bindings(): void
    {
        $this->assertFalse(DbflowRuntime::isEnabled());
        $this->assertFalse($this->app->bound(UserResolver::class));
        $this->assertFalse($this->app->bound(AssigneeResolverRegistry::class));
        $this->assertFalse($this->app->bound(StartWorkflow::class));
    }

    #[Test]
    public function runtime_actions_throw_when_disabled(): void
    {
        $this->expectException(WorkflowNotAvailableException::class);

        DBFlow::start('disabled_flow', new ContextTestSubject, 1);
    }
}
