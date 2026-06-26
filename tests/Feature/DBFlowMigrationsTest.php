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

use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class DBFlowMigrationsTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const EXPECTED_TABLES = [
        'dbflow_workflows',
        'dbflow_workflow_versions',
        'dbflow_workflow_instances',
        'dbflow_workflow_tasks',
        'dbflow_workflow_task_assignments',
        'dbflow_workflow_logs',
    ];

    #[Test]
    public function package_migrations_create_all_dbflow_tables(): void
    {
        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Expected dbflow migration to create table [{$table}].",
            );
        }
    }

    #[Test]
    public function test_users_fixture_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
    }
}
