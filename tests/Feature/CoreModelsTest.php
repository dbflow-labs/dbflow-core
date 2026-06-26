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

use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CoreModelsTest extends TestCase
{
    #[Test]
    public function core_eloquent_models_expose_expected_table_names(): void
    {
        $this->assertSame('dbflow_workflows', (new Workflow)->getTable());
        $this->assertSame('dbflow_workflow_versions', (new WorkflowVersion)->getTable());
        $this->assertSame('dbflow_workflow_instances', (new WorkflowInstance)->getTable());
        $this->assertSame('dbflow_workflow_tasks', (new WorkflowTask)->getTable());
        $this->assertSame('dbflow_workflow_task_assignments', (new WorkflowTaskAssignment)->getTable());
        $this->assertSame('dbflow_workflow_logs', (new WorkflowLog)->getTable());
    }
}
