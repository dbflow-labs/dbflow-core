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

use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Support\DbflowRuntime;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DbflowEnabledTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;

    #[Test]
    public function enabled_config_registers_bindings_and_allows_runtime_actions(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Enabled User',
            'email' => 'enabled-user@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'enabled_runtime_flow',
            'Enabled Runtime Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'ENABLED-001']);

        $instance = DBFlow::start('enabled_runtime_flow', $subject, $assignee->getKey());

        $this->assertTrue(DbflowRuntime::isEnabled());
        $this->assertSame('enabled_runtime_flow', $instance->workflow->key);
    }
}
