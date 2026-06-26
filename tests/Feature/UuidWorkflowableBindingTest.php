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
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Fixtures\IntegerTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\Fixtures\UuidTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class UuidWorkflowableBindingTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;
    use RefreshDatabase;

    #[Test]
    public function workflow_instances_table_stores_workflowable_id_as_string(): void
    {
        $column = Schema::getColumnType('dbflow_workflow_instances', 'workflowable_id');

        $this->assertContains($column, ['string', 'varchar']);
    }

    #[Test]
    public function uuid_workflowable_id_persists_and_morph_lookup_works(): void
    {
        $this->createMinimalPublishedWorkflow('uuid_binding_test');

        $user = TestUser::query()->create([
            'name' => 'Starter',
            'email' => 'starter@example.com',
        ]);

        $subject = UuidTestSubject::query()->create([
            'reference_code' => 'UUID-001',
        ]);

        $instance = DBFlow::start('uuid_binding_test', $subject, $user);

        $this->assertSame($subject->getMorphClass(), $instance->workflowable_type);
        $this->assertSame((string) $subject->getKey(), $instance->workflowable_id);
        $this->assertTrue($subject->fresh()->hasRunningWorkflow('uuid_binding_test'));

        $loaded = WorkflowInstance::query()->findOrFail($instance->getKey());
        $this->assertInstanceOf(UuidTestSubject::class, $loaded->workflowable);
        $this->assertSame($subject->getKey(), $loaded->workflowable->getKey());
    }

    #[Test]
    public function integer_workflowable_id_persists_and_morph_lookup_works(): void
    {
        $this->createMinimalPublishedWorkflow('integer_binding_test');

        $user = TestUser::query()->create([
            'name' => 'Starter',
            'email' => 'integer-starter@example.com',
        ]);

        $subject = IntegerTestSubject::query()->create([
            'reference_code' => 'INT-001',
        ]);

        $instance = DBFlow::start('integer_binding_test', $subject, $user);

        $this->assertSame((string) $subject->getKey(), $instance->workflowable_id);
        $this->assertTrue($subject->fresh()->hasRunningWorkflow('integer_binding_test'));

        $loaded = WorkflowInstance::query()->findOrFail($instance->getKey());
        $this->assertInstanceOf(IntegerTestSubject::class, $loaded->workflowable);
        $this->assertSame($subject->getKey(), $loaded->workflowable->getKey());
    }
}
