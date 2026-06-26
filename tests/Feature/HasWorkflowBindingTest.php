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
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Fixtures\IntegerTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class HasWorkflowBindingTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;
    use RefreshDatabase;

    #[Test]
    public function dbflow_workflows_table_has_model_type_column(): void
    {
        $this->assertTrue(Schema::hasColumn('dbflow_workflows', 'model_type'));
    }

    #[Test]
    public function code_binding_mode_does_not_auto_start_workflow_on_model_creation(): void
    {
        config(['dbflow.binding_mode' => 'code']);

        $workflow = $this->createMinimalPublishedWorkflow('code_mode_binding');
        $workflow->forceFill(['model_type' => IntegerTestSubject::class])->save();

        $subject = IntegerTestSubject::query()->create([
            'reference_code' => 'CODE-001',
        ]);

        $this->assertFalse($subject->hasRunningWorkflow('code_mode_binding'));
    }

    #[Test]
    public function ui_binding_mode_auto_starts_matching_published_workflow_on_model_creation(): void
    {
        config(['dbflow.binding_mode' => 'ui']);

        $user = TestUser::query()->create([
            'name' => 'Starter',
            'email' => 'ui-binding@example.com',
        ]);
        $this->be($user);

        $workflow = $this->createMinimalPublishedWorkflow('ui_mode_binding');
        $workflow->forceFill(['model_type' => IntegerTestSubject::class])->save();

        $subject = IntegerTestSubject::query()->create([
            'reference_code' => 'UI-001',
        ]);

        $this->assertTrue($subject->fresh()->hasRunningWorkflow('ui_mode_binding'));
    }

    #[Test]
    public function ui_binding_mode_ignores_workflows_without_matching_model_type(): void
    {
        config(['dbflow.binding_mode' => 'ui']);

        $workflow = $this->createMinimalPublishedWorkflow('unbound_workflow');
        $workflow->forceFill(['model_type' => TestUser::class])->save();

        $subject = IntegerTestSubject::query()->create([
            'reference_code' => 'UI-002',
        ]);

        $this->assertFalse($subject->hasRunningWorkflow('unbound_workflow'));
    }

    #[Test]
    public function start_workflow_explicitly_starts_workflow_in_code_mode(): void
    {
        config(['dbflow.binding_mode' => 'code']);

        $this->createMinimalPublishedWorkflow('explicit_start');

        $user = TestUser::query()->create([
            'name' => 'Starter',
            'email' => 'explicit@example.com',
        ]);
        $this->be($user);

        $subject = IntegerTestSubject::query()->create([
            'reference_code' => 'EXPLICIT-001',
        ]);

        $subject->startWorkflow('explicit_start');

        $this->assertTrue($subject->fresh()->hasRunningWorkflow('explicit_start'));
    }
}
