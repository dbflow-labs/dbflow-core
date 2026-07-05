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

namespace DbflowLabs\Core\Tests\Feature\Engine;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AnyAllApprovalFlowTest extends TestCase
{
    use RegistersEngineTestResources;

    #[Test]
    public function any_mode_advances_after_first_approver_and_skips_remaining_assignments(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $this->publishWorkflow('any_team_approval', ApprovalMode::Any);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'ANY-001']);
        $instance = DBFlow::start('any_team_approval', $subject, $users['first']->getKey());

        $task = $this->pendingTaskForNode($instance, 'team_approval');
        $this->assertSame(ApprovalMode::Any, $task->approval_mode);
        $this->assertCount(2, $task->assignments);

        DBFlow::approve($task, $users['first']->getKey());

        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);

        $skipped = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Skipped)
            ->count();

        $this->assertSame(1, $skipped);
    }

    #[Test]
    public function all_mode_requires_every_assignee_before_advancing(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $this->publishWorkflow('all_team_approval', ApprovalMode::All);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'ALL-001']);
        $instance = DBFlow::start('all_team_approval', $subject, $users['first']->getKey());

        $task = $this->pendingTaskForNode($instance, 'team_approval');
        $this->assertSame(ApprovalMode::All, $task->approval_mode);

        DBFlow::approve($task, $users['first']->getKey());

        $task->refresh();
        $instance->refresh();
        $this->assertSame(WorkflowTaskStatus::Pending, $task->status);
        $this->assertSame('team_approval', $instance->current_node_key);
        $this->assertSame(WorkflowInstanceStatus::Running, $instance->status);

        DBFlow::approve($task, $users['second']->getKey());

        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end_approved', $instance->current_node_key);
    }

    private function publishWorkflow(string $key, ApprovalMode $approvalMode): void
    {
        $definition = [
            'key' => $key,
            'name' => 'Team Approval',
            'schema_version' => '1.0',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'team_approval',
                    'type' => 'approval',
                    'name' => 'Team Approval',
                    'config' => [
                        'approval_mode' => $approvalMode->value,
                        'assignees' => [
                            'type' => 'permission',
                            'value' => 'sequential_team',
                        ],
                    ],
                ],
                [
                    'key' => 'end_approved',
                    'type' => 'end',
                    'name' => 'Approved',
                    'config' => ['status' => 'approved'],
                ],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'team_approval', 'is_default' => true],
                ['from' => 'team_approval', 'to' => 'end_approved'],
            ],
        ];

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);
    }

    private function pendingTaskForNode(WorkflowInstance $instance, string $nodeKey): WorkflowTask
    {
        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('node_key', $nodeKey)
            ->where('status', WorkflowTaskStatus::Pending)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $task);

        return $task;
    }
}
