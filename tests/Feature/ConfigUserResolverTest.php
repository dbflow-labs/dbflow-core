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

final class ConfigUserResolverTest extends TestCase
{
    #[Test]
    public function resolver_returns_configured_test_user_model_class(): void
    {
        $resolver = new ConfigUserResolver;

        $this->assertSame(TestUser::class, $resolver->modelClass());
    }

    #[Test]
    public function resolver_find_returns_test_user_by_id(): void
    {
        $user = TestUser::query()->create([
            'name' => 'Package Tester',
            'email' => 'tester@dbflow.dev',
        ]);

        $resolver = new ConfigUserResolver;
        $found = $resolver->find($user->getKey());

        $this->assertInstanceOf(TestUser::class, $found);
        $this->assertSame($user->getKey(), $found?->getKey());
        $this->assertSame('Package Tester', $found?->name);
    }

    #[Test]
    public function resolver_find_returns_null_for_empty_id(): void
    {
        $resolver = new ConfigUserResolver;

        $this->assertNull($resolver->find(null));
        $this->assertNull($resolver->find(''));
        $this->assertNull($resolver->find(0));
    }
}
