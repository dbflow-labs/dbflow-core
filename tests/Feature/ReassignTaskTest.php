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

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskReassigned;
use DbflowLabs\Core\Exceptions\TaskNotPendingException;
use DbflowLabs\Core\Exceptions\UserCannotReassignTaskException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Concerns\LoadsBlueprintFixtures;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ReassignTaskTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;
    use LoadsBlueprintFixtures;
    use RegistersEngineTestResources;

    #[Test]
    public function reassign_replaces_pending_assignment_and_keeps_task_pending(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'From Assignee',
            'email' => 'from-assignee@dbflow.dev',
        ]);

        $replacement = TestUser::query()->create([
            'name' => 'To Assignee',
            'email' => 'to-assignee@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'reassign_flow',
            'Reassign Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-001']);
        $instance = DBFlow::start('reassign_flow', $subject, $assignee->getKey());

        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        Event::fake([TaskReassigned::class]);

        $result = DBFlow::reassign(
            $task,
            $assignee->getKey(),
            (string) $replacement->getKey(),
            'Handing off while on leave.',
        );

        $this->assertSame(WorkflowInstanceStatus::Running, $result->status);
        $this->assertSame('approval', $result->current_node_key);

        $task->refresh();
        $this->assertSame(WorkflowTaskStatus::Pending, $task->status);

        $fromAssignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('assignee_user_id', (string) $assignee->getKey())
            ->firstOrFail();

        $this->assertSame(WorkflowTaskAssignmentStatus::Reassigned, $fromAssignment->status);
        $this->assertNotNull($fromAssignment->acted_at);

        $toAssignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('assignee_user_id', (string) $replacement->getKey())
            ->firstOrFail();

        $this->assertSame(WorkflowTaskAssignmentStatus::Pending, $toAssignment->status);

        $log = WorkflowLog::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('event', WorkflowLogEvent::TaskReassigned->value)
            ->firstOrFail();

        $payload = is_array($log->payload) ? $log->payload : [];
        $this->assertSame((string) $assignee->getKey(), $payload['from_assignee_user_id'] ?? null);
        $this->assertSame((string) $replacement->getKey(), $payload['to_assignee_user_id'] ?? null);
        $this->assertSame($toAssignment->getKey(), (int) ($payload['assignment_id'] ?? 0));
        $this->assertSame($fromAssignment->getKey(), (int) ($payload['previous_assignment_id'] ?? 0));
        $this->assertSame('Handing off while on leave.', $log->comment);

        Event::assertDispatched(TaskReassigned::class, function (TaskReassigned $event) use ($task, $instance, $fromAssignment, $toAssignment, $assignee, $replacement): bool {
            return $event->task->is($task)
                && $event->instance->is($instance)
                && $event->previousAssignment->is($fromAssignment)
                && $event->newAssignment->is($toAssignment)
                && $event->comment === 'Handing off while on leave.';
        });
    }

    #[Test]
    public function non_assignee_cannot_reassign(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Owner Assignee',
            'email' => 'owner-assignee@dbflow.dev',
        ]);

        $intruder = TestUser::query()->create([
            'name' => 'Intruder',
            'email' => 'intruder@dbflow.dev',
        ]);

        $replacement = TestUser::query()->create([
            'name' => 'Replacement',
            'email' => 'replacement@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'reassign_guard_flow',
            'Reassign Guard Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-GUARD-001']);
        $instance = DBFlow::start('reassign_guard_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $this->expectException(UserCannotReassignTaskException::class);

        DBFlow::reassign($task, $intruder->getKey(), (string) $replacement->getKey());
    }

    #[Test]
    public function cannot_reassign_to_the_same_user(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Same User',
            'email' => 'same-user@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'reassign_same_user_flow',
            'Reassign Same User Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-SAME-001']);
        $instance = DBFlow::start('reassign_same_user_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $this->expectException(UserCannotReassignTaskException::class);

        DBFlow::reassign($task, $assignee->getKey(), (string) $assignee->getKey());
    }

    #[Test]
    public function cannot_reassign_to_user_who_already_has_an_assignment(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $this->publishTeamWorkflow('reassign_duplicate_target_flow', ApprovalMode::Any);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-DUP-001']);
        $instance = DBFlow::start('reassign_duplicate_target_flow', $subject, $users['first']->getKey());
        $task = $this->pendingTaskForNode($instance, 'team_approval');

        $this->expectException(UserCannotReassignTaskException::class);

        DBFlow::reassign(
            $task,
            $users['first']->getKey(),
            (string) $users['second']->getKey(),
        );
    }

    #[Test]
    public function cannot_reassign_a_non_pending_task(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Approver',
            'email' => 'approver@dbflow.dev',
        ]);

        $replacement = TestUser::query()->create([
            'name' => 'Replacement',
            'email' => 'replacement-two@dbflow.dev',
        ]);

        $this->createMinimalPublishedWorkflow(
            'reassign_terminal_task_flow',
            'Reassign Terminal Task Flow',
            (string) $assignee->getKey(),
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-TERM-001']);
        $instance = DBFlow::start('reassign_terminal_task_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        DBFlow::approve($task, $assignee->getKey());

        $this->expectException(TaskNotPendingException::class);

        DBFlow::reassign($task->fresh(), $assignee->getKey(), (string) $replacement->getKey());
    }

    #[Test]
    public function sequential_mode_allows_only_current_sequence_assignee_to_reassign(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $this->publishTeamWorkflow('reassign_sequential_flow', ApprovalMode::Sequential);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-SEQ-001']);
        $instance = DBFlow::start('reassign_sequential_flow', $subject, $users['first']->getKey());
        $task = $this->pendingTaskForNode($instance, 'team_approval');

        $replacement = TestUser::query()->create([
            'name' => 'Sequential Replacement',
            'email' => 'sequential-replacement@dbflow.dev',
        ]);

        $this->expectException(UserCannotReassignTaskException::class);

        DBFlow::reassign(
            $task,
            $users['second']->getKey(),
            (string) $replacement->getKey(),
        );
    }

    #[Test]
    public function sequential_mode_current_assignee_can_reassign_and_inherits_sequence(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $this->publishTeamWorkflow('reassign_sequential_success_flow', ApprovalMode::Sequential);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-SEQ-OK-001']);
        $instance = DBFlow::start('reassign_sequential_success_flow', $subject, $users['first']->getKey());
        $task = $this->pendingTaskForNode($instance, 'team_approval');

        $replacement = TestUser::query()->create([
            'name' => 'Sequential Replacement OK',
            'email' => 'sequential-replacement-ok@dbflow.dev',
        ]);

        DBFlow::reassign(
            $task,
            $users['first']->getKey(),
            (string) $replacement->getKey(),
        );

        $newAssignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('assignee_user_id', (string) $replacement->getKey())
            ->firstOrFail();

        $this->assertSame(WorkflowTaskAssignmentStatus::Pending, $newAssignment->status);
        $this->assertSame(1, $newAssignment->sequence);

        $secondAssignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('assignee_user_id', (string) $users['second']->getKey())
            ->firstOrFail();

        $this->assertSame(WorkflowTaskAssignmentStatus::Pending, $secondAssignment->status);
        $this->assertSame(2, $secondAssignment->sequence);
    }

    #[Test]
    public function all_mode_reassign_does_not_reset_completed_approvals(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $this->publishTeamWorkflow('reassign_all_mode_flow', ApprovalMode::All);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'REASSIGN-ALL-001']);
        $instance = DBFlow::start('reassign_all_mode_flow', $subject, $users['first']->getKey());
        $task = $this->pendingTaskForNode($instance, 'team_approval');

        DBFlow::approve($task, $users['first']->getKey());

        $replacement = TestUser::query()->create([
            'name' => 'All Mode Replacement',
            'email' => 'all-mode-replacement@dbflow.dev',
        ]);

        DBFlow::reassign(
            $task->fresh(),
            $users['second']->getKey(),
            (string) $replacement->getKey(),
        );

        $approvedCount = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Approved)
            ->count();

        $this->assertSame(1, $approvedCount);
        $this->assertSame(WorkflowTaskStatus::Pending, $task->fresh()->status);
    }

    private function publishTeamWorkflow(string $key, ApprovalMode $approvalMode): void
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
