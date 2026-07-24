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
use DbflowLabs\Core\Capabilities\RuntimeCapabilityRegistry;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\AssignmentSource;
use DbflowLabs\Core\Enums\DelegationLifecycle;
use DbflowLabs\Core\Enums\RuntimeCapability;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Exceptions\DelegationCycleException;
use DbflowLabs\Core\Exceptions\IdempotencyConflictException;
use DbflowLabs\Core\Exceptions\InvalidDelegationException;
use DbflowLabs\Core\Exceptions\UserCannotApproveTaskException;
use DbflowLabs\Core\Exceptions\UserCannotReassignTaskException;
use DbflowLabs\Core\Models\WorkflowDelegation;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\WorkflowTaskQueryService;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

final class Stage11BDelegationTest extends TestCase
{
    use RegistersEngineTestResources;

    #[Test]
    public function delegation_lifecycle_is_computed_with_half_open_utc_intervals(): void
    {
        $rule = new WorkflowDelegation([
            'delegator_user_id' => '1',
            'delegate_user_id' => '2',
            'starts_at' => Carbon::parse('2026-07-23 10:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-07-23 12:00:00', 'UTC'),
        ]);

        $this->assertSame(DelegationLifecycle::Scheduled, $rule->lifecycle(Carbon::parse('2026-07-23 09:59:59', 'UTC')));
        $this->assertSame(DelegationLifecycle::Active, $rule->lifecycle(Carbon::parse('2026-07-23 10:00:00', 'UTC')));
        $this->assertSame(DelegationLifecycle::Active, $rule->lifecycle(Carbon::parse('2026-07-23 11:59:59', 'UTC')));
        $this->assertSame(DelegationLifecycle::Expired, $rule->lifecycle(Carbon::parse('2026-07-23 12:00:00', 'UTC')));

        $rule->revoked_at = Carbon::parse('2026-07-23 11:00:00', 'UTC');
        $this->assertSame(DelegationLifecycle::Revoked, $rule->lifecycle(Carbon::parse('2026-07-23 11:30:00', 'UTC')));
    }

    #[Test]
    public function create_delegation_rejects_self_overlap_and_cycles(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        $this->expectException(InvalidDelegationException::class);
        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['first']->getKey(),
            $now,
            $now->copy()->addDay(),
        );
    }

    #[Test]
    public function overlapping_same_scope_rules_are_rejected(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            $now,
            $now->copy()->addDays(2),
            reason: 'primary',
        );

        $this->expectException(InvalidDelegationException::class);
        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['director']->getKey(),
            $now->copy()->addDay(),
            $now->copy()->addDays(3),
            reason: 'overlap',
        );
    }

    #[Test]
    public function adjacent_half_open_intervals_are_allowed(): void
    {
        $users = $this->seedEngineUsers();
        $start = Carbon::parse('2026-07-23 10:00:00', 'UTC');
        $mid = Carbon::parse('2026-07-23 12:00:00', 'UTC');
        $end = Carbon::parse('2026-07-23 14:00:00', 'UTC');

        DBFlow::createDelegation($users['first']->getKey(), $users['second']->getKey(), $start, $mid, reason: 'a');
        $second = DBFlow::createDelegation($users['first']->getKey(), $users['director']->getKey(), $mid, $end, reason: 'b');

        $this->assertNotNull($second->getKey());
    }

    #[Test]
    public function cycle_a_to_b_to_a_is_rejected(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            $now,
            $now->copy()->addDays(2),
        );

        $this->expectException(DelegationCycleException::class);
        DBFlow::createDelegation(
            $users['second']->getKey(),
            $users['first']->getKey(),
            $now,
            $now->copy()->addDays(2),
        );
    }

    #[Test]
    public function active_global_delegation_applies_on_new_assignment_when_enabled(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
            reason: 'leave',
        );

        $this->publishDelegatedWorkflow('deleg_global_flow', (string) $users['first']->getKey(), true);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'DEL-GLOBAL']);
        $instance = DBFlow::start('deleg_global_flow', $subject, $users['first']->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $assignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->firstOrFail();

        $this->assertSame((string) $users['first']->getKey(), (string) $assignment->assignee_user_id);
        $this->assertSame((string) $users['first']->getKey(), $assignment->originalAssigneeUserId());
        $this->assertSame((string) $users['second']->getKey(), $assignment->effectiveAssigneeUserId());
        $this->assertSame(AssignmentSource::Delegation, $assignment->assignmentSourceOrDirect());
        $this->assertNotNull($assignment->delegation_id);

        DBFlow::approve($task, $users['second']->getKey());
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->fresh()->status);
    }

    #[Test]
    public function delegation_disabled_creates_direct_assignment(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
        );

        $this->publishDelegatedWorkflow('deleg_disabled_flow', (string) $users['first']->getKey(), false);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'DEL-OFF']);
        $instance = DBFlow::start('deleg_disabled_flow', $subject, $users['first']->getKey());
        $assignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $instance->tasks()->firstOrFail()->getKey())
            ->firstOrFail();

        $this->assertSame(AssignmentSource::Direct, $assignment->assignmentSourceOrDirect());
        $this->assertSame((string) $users['first']->getKey(), $assignment->effectiveAssigneeUserId());
    }

    #[Test]
    public function revoked_delegation_does_not_apply_to_future_tasks(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        $rule = DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
        );

        DBFlow::revokeDelegation($rule, $users['first']->getKey(), 'back');

        $this->publishDelegatedWorkflow('deleg_revoked_flow', (string) $users['first']->getKey(), true);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'DEL-REV']);
        $instance = DBFlow::start('deleg_revoked_flow', $subject, $users['first']->getKey());
        $assignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $instance->tasks()->firstOrFail()->getKey())
            ->firstOrFail();

        $this->assertSame(AssignmentSource::Direct, $assignment->assignmentSourceOrDirect());
        $this->assertSame((string) $users['first']->getKey(), $assignment->effectiveAssigneeUserId());
    }

    #[Test]
    public function all_mode_delegate_can_satisfy_multiple_represented_responsibilities(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['director']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
        );
        DBFlow::createDelegation(
            $users['second']->getKey(),
            $users['director']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
            workflowKey: 'deleg_all_multi',
        );

        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $this->publishTeamDelegatedWorkflow('deleg_all_multi', ApprovalMode::All, true);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'DEL-ALL']);
        $instance = DBFlow::start('deleg_all_multi', $subject, $users['first']->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $this->assertSame(2, WorkflowTaskAssignment::query()->where('workflow_task_id', $task->getKey())->count());

        DBFlow::approve($task, $users['director']->getKey());

        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->fresh()->status);
        $this->assertSame(
            2,
            WorkflowTaskAssignment::query()
                ->where('workflow_task_id', $task->getKey())
                ->where('status', WorkflowTaskAssignmentStatus::Approved)
                ->count(),
        );
    }

    #[Test]
    public function sequential_delegate_cannot_act_on_future_sequence(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        DBFlow::createDelegation(
            $users['second']->getKey(),
            $users['director']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
        );

        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);
        $this->publishTeamDelegatedWorkflow('deleg_seq', ApprovalMode::Sequential, true);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'DEL-SEQ']);
        $instance = DBFlow::start('deleg_seq', $subject, $users['first']->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $this->expectException(UserCannotApproveTaskException::class);
        DBFlow::approve($task, $users['director']->getKey());
    }

    #[Test]
    public function reassignment_is_idempotent_and_preserves_original_responsibility(): void
    {
        $users = $this->seedEngineUsers();
        $this->publishDelegatedWorkflow('reassign_idem_flow', (string) $users['first']->getKey(), false);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'RE-IDEM']);
        $instance = DBFlow::start('reassign_idem_flow', $subject, $users['first']->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        DBFlow::reassign($task, $users['first']->getKey(), (string) $users['second']->getKey(), 'move', 'op-1');
        DBFlow::reassign($task->fresh(), $users['first']->getKey(), (string) $users['second']->getKey(), 'move', 'op-1');

        $pending = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->get();

        $this->assertCount(1, $pending);
        $this->assertSame((string) $users['first']->getKey(), $pending->first()->originalAssigneeUserId());
        $this->assertSame((string) $users['second']->getKey(), $pending->first()->effectiveAssigneeUserId());
        $this->assertSame(AssignmentSource::Reassignment, $pending->first()->assignmentSourceOrDirect());

        $this->expectException(IdempotencyConflictException::class);
        DBFlow::reassign($task->fresh(), $users['first']->getKey(), (string) $users['director']->getKey(), 'conflict', 'op-1');
    }

    #[Test]
    public function migrate_pending_tasks_uses_reassign_and_supports_dry_run(): void
    {
        $users = $this->seedEngineUsers();
        $this->publishDelegatedWorkflow('migrate_flow', (string) $users['first']->getKey(), false);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'MIG-1']);
        $instance = DBFlow::start('migrate_flow', $subject, $users['first']->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $rule = DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            Carbon::now('UTC')->subHour(),
            Carbon::now('UTC')->addDays(2),
            reason: 'migrate',
        );

        $dry = DBFlow::migratePendingTasksToDelegate($rule, $users['first']->getKey(), dryRun: true);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(1, $dry['matched']);
        $this->assertSame(
            WorkflowTaskAssignmentStatus::Pending,
            WorkflowTaskAssignment::query()->where('workflow_task_id', $task->getKey())->where('assignee_user_id', $users['first']->getKey())->firstOrFail()->status,
        );

        $summary = DBFlow::migratePendingTasksToDelegate($rule, $users['first']->getKey(), batchKey: 'batch-1');
        $this->assertSame(1, $summary['migrated']);

        $pending = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->firstOrFail();

        $this->assertSame((string) $users['second']->getKey(), $pending->effectiveAssigneeUserId());
    }

    #[Test]
    public function effective_assignee_appears_in_pending_task_query(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');
        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
        );
        $this->publishDelegatedWorkflow('query_eff_flow', (string) $users['first']->getKey(), true);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'Q-EFF']);
        DBFlow::start('query_eff_flow', $subject, $users['first']->getKey());

        $forDelegate = app(WorkflowTaskQueryService::class)->countPendingTasksForUser((string) $users['second']->getKey());
        $forOriginal = app(WorkflowTaskQueryService::class)->countPendingTasksForUser((string) $users['first']->getKey());

        $this->assertSame(1, $forDelegate);
        $this->assertSame(1, $forOriginal);
    }

    #[Test]
    public function node_delegation_requires_capability_and_v10_does_not(): void
    {
        $definition = $this->delegatedDefinition('cap_flow', '1', true);
        $this->assertTrue(app(WorkflowDefinitionValidator::class)->validate($definition)->isValid());

        app(RuntimeCapabilityRegistry::class)->disable(RuntimeCapability::Delegation);
        $this->assertFalse(app(WorkflowDefinitionValidator::class)->validate($definition)->isValid());

        app(RuntimeCapabilityRegistry::class)->enable(RuntimeCapability::Delegation);

        $v10 = $this->delegatedDefinition('v10_flow', '1', false);
        unset($v10['schema_version']);
        $this->assertTrue(app(WorkflowDefinitionValidator::class)->validate($v10)->isValid());
    }

    #[Test]
    public function migrate_pending_tasks_rejects_non_active_delegation(): void
    {
        $users = $this->seedEngineUsers();
        $rule = DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            Carbon::now('UTC')->addDay(),
            Carbon::now('UTC')->addDays(3),
            reason: 'scheduled',
        );

        $this->expectException(InvalidDelegationException::class);
        DBFlow::migratePendingTasksToDelegate($rule, $users['first']->getKey());
    }

    #[Test]
    public function assignment_id_disambiguates_multi_responsibility_reassignment(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');

        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['director']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
        );
        DBFlow::createDelegation(
            $users['second']->getKey(),
            $users['director']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
            workflowKey: 'deleg_reassign_multi',
        );

        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);
        $this->publishTeamDelegatedWorkflow('deleg_reassign_multi', ApprovalMode::All, true);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'DEL-RE-MULTI']);
        $instance = DBFlow::start('deleg_reassign_multi', $subject, $users['first']->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $firstAssignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('assignee_user_id', (string) $users['first']->getKey())
            ->firstOrFail();

        $replacement = TestUser::query()->create([
            'name' => 'Replacement',
            'email' => 'replacement-multi@dbflow.dev',
        ]);

        try {
            DBFlow::reassign($task, $users['director']->getKey(), (string) $replacement->getKey());
            $this->fail('Ambiguous multi-responsibility reassignment should be rejected.');
        } catch (UserCannotReassignTaskException) {
            // expected
        }

        DBFlow::reassign(
            $task,
            $users['director']->getKey(),
            (string) $replacement->getKey(),
            'one of two',
            null,
            $firstAssignment->getKey(),
        );

        $this->assertSame(
            WorkflowTaskAssignmentStatus::Reassigned,
            $firstAssignment->fresh()->status,
        );
    }

    #[Test]
    public function same_target_as_effective_assignee_is_rejected(): void
    {
        $users = $this->seedEngineUsers();
        $now = Carbon::now('UTC');
        DBFlow::createDelegation(
            $users['first']->getKey(),
            $users['second']->getKey(),
            $now->copy()->subHour(),
            $now->copy()->addDays(2),
        );
        $this->publishDelegatedWorkflow('same_eff_flow', (string) $users['first']->getKey(), true);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SAME-EFF']);
        $instance = DBFlow::start('same_eff_flow', $subject, $users['first']->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $this->expectException(UserCannotReassignTaskException::class);
        DBFlow::reassign($task, $users['first']->getKey(), (string) $users['second']->getKey());
    }

    private function publishDelegatedWorkflow(string $key, string $assigneeUserId, bool $delegationEnabled): void
    {
        $definition = $this->delegatedDefinition($key, $assigneeUserId, $delegationEnabled);
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function delegatedDefinition(string $key, string $assigneeUserId, bool $delegationEnabled): array
    {
        $config = [
            'approval_mode' => ApprovalMode::Any->value,
            'assignees' => [
                'type' => 'user',
                'value' => $assigneeUserId,
            ],
        ];

        if ($delegationEnabled) {
            $config['delegation'] = ['enabled' => true];
        }

        return [
            'key' => $key,
            'name' => $key,
            'schema_version' => '1.1',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'approval',
                    'type' => 'approval',
                    'name' => 'Approval',
                    'config' => $config,
                ],
                [
                    'key' => 'end',
                    'type' => 'end',
                    'name' => 'End',
                    'config' => ['status' => 'approved'],
                ],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'approval', 'is_default' => true],
                ['from' => 'approval', 'to' => 'end'],
            ],
        ];
    }

    private function publishTeamDelegatedWorkflow(string $key, ApprovalMode $mode, bool $delegationEnabled): void
    {
        $config = [
            'approval_mode' => $mode->value,
            'assignees' => [
                'type' => 'permission',
                'value' => 'sequential_team',
            ],
        ];

        if ($delegationEnabled) {
            $config['delegation'] = ['enabled' => true];
        }

        $definition = [
            'key' => $key,
            'name' => $key,
            'schema_version' => '1.1',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'team_approval',
                    'type' => 'approval',
                    'name' => 'Team',
                    'config' => $config,
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
}
