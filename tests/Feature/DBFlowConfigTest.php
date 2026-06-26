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

use DbflowLabs\Core\Support\ConfigUserResolver;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DBFlowConfigTest extends TestCase
{
    #[Test]
    public function core_configuration_merges_package_defaults(): void
    {
        $this->assertTrue((bool) config('dbflow.enabled'));
        $this->assertSame('code', config('dbflow.binding_mode'));
        $this->assertSame(ConfigUserResolver::class, config('dbflow.auth.resolver'));
        $this->assertFalse((bool) config('dbflow.visual_builder_enabled'));
    }

    #[Test]
    public function test_environment_overrides_auth_model(): void
    {
        $this->assertSame(TestUser::class, config('dbflow.auth.model'));
    }
}
