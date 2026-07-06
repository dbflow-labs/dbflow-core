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
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CancelWorkflowTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;

    #[Test]
    public function cancel_sets_terminal_states_and_clears_active_key(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Cancel Assignee',
            'email' => 'cancel-assignee@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'cancel_flow',
            'Cancel Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'CANCEL-001']);
        $instance = DBFlow::start('cancel_flow', $subject, $assignee->getKey());

        $this->assertNotNull($instance->active_key);
        $this->assertSame(WorkflowInstanceStatus::Running, $instance->status);

        $cancelled = DBFlow::cancel($instance->fresh(), $assignee->getKey(), 'Withdrawn by submitter.');

        $this->assertSame(WorkflowInstanceStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->active_key);
        $this->assertNotNull($cancelled->cancelled_at);

        $tasks = WorkflowTask::query()
            ->where('workflow_instance_id', $cancelled->getKey())
            ->get();

        $this->assertNotEmpty($tasks);

        foreach ($tasks as $task) {
            if ($task->status === WorkflowTaskStatus::Pending) {
                $this->fail('Expected no pending tasks after cancel.');
            }
        }

        $pendingAssignments = WorkflowTaskAssignment::query()
            ->whereIn('workflow_task_id', $tasks->pluck('id'))
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->count();

        $this->assertSame(0, $pendingAssignments);
    }

    #[Test]
    public function cancel_writes_task_cancelled_audit_for_each_pending_task(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Audit Assignee',
            'email' => 'audit-assignee@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'cancel_audit_flow',
            'Cancel Audit Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'CANCEL-AUDIT-001']);
        $instance = DBFlow::start('cancel_audit_flow', $subject, $assignee->getKey());

        $pendingTaskCount = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('status', WorkflowTaskStatus::Pending)
            ->count();

        $this->assertGreaterThan(0, $pendingTaskCount);

        DBFlow::cancel($instance->fresh(), $assignee->getKey(), 'Cancelled for audit test.');

        $taskCancelledLogs = WorkflowLog::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('event', WorkflowLogEvent::TaskCancelled->value)
            ->get();

        $this->assertCount($pendingTaskCount, $taskCancelledLogs);

        $this->assertTrue(
            WorkflowLog::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('event', WorkflowLogEvent::WorkflowCancelled->value)
                ->exists(),
        );
    }

    #[Test]
    public function cancel_on_terminal_instance_is_idempotent_and_does_not_duplicate_logs(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Idempotent Assignee',
            'email' => 'idempotent-assignee@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'cancel_idempotent_flow',
            'Cancel Idempotent Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'CANCEL-IDEM-001']);
        $instance = DBFlow::start('cancel_idempotent_flow', $subject, $assignee->getKey());

        DBFlow::cancel($instance->fresh(), $assignee->getKey());

        $logCountAfterFirstCancel = WorkflowLog::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->count();

        $secondCancel = DBFlow::cancel($instance->fresh(), $assignee->getKey());

        $this->assertSame(WorkflowInstanceStatus::Cancelled, $secondCancel->status);

        $logCountAfterSecondCancel = WorkflowLog::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->count();

        $this->assertSame($logCountAfterFirstCancel, $logCountAfterSecondCancel);
    }
}
