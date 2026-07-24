<?php

declare(strict_types=1);

namespace DbflowLabs\Core\Tests\Feature;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\Capabilities\RuntimeCapabilityRegistry;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\AssignmentSource;
use DbflowLabs\Core\Enums\RuntimeCapability;
use DbflowLabs\Core\Enums\SlaEventStatus;
use DbflowLabs\Core\Enums\SlaEventType;
use DbflowLabs\Core\Enums\SlaPolicySource;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskBecameOverdue;
use DbflowLabs\Core\Events\TaskReminderDispatched;
use DbflowLabs\Core\Jobs\ProcessSlaEventJob;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowSlaEvent;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Support\WorkflowBuilderNodeFactory;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

final class Stage11CSlaTest extends TestCase
{
    #[Test]
    public function sla_definition_requires_capability_when_disabled(): void
    {
        app(RuntimeCapabilityRegistry::class)->disable(RuntimeCapability::Sla);

        $definition = $this->workflowDefinitionWithSla('PT2H');
        $result = app(WorkflowDefinitionValidator::class)->validate($definition);

        $this->assertFalse($result->isValid());
        $this->assertSame('missing_runtime_capability', $result->errors()[0]['code']);
    }

    #[Test]
    public function task_materializes_due_at_snapshot_and_events(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Assignee',
            'email' => 'sla-assignee@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');

        $this->publishWorkflowWithSla('sla_materialize_flow', (string) $assignee->getKey(), 'PT2H', [
            ['before_due' => 'PT30M', 'channel' => 'event'],
        ]);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-MAT-001']);
        $instance = DBFlow::start('sla_materialize_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $this->assertSame(SlaPolicySource::V11Sla->value, $task->sla_policy_source);
        $this->assertNotNull($task->sla_policy_snapshot);
        $this->assertSame('2026-07-07 11:00:00', $task->due_at?->format('Y-m-d H:i:s'));

        $this->assertSame(2, WorkflowSlaEvent::query()->where('workflow_task_id', $task->getKey())->count());
        $this->assertSame(1, WorkflowSlaEvent::query()->where('event_type', SlaEventType::Reminder)->count());
        $this->assertSame(1, WorkflowSlaEvent::query()->where('event_type', SlaEventType::Overdue)->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function reminder_is_dispatched_and_overdue_marks_task_without_rejecting(): void
    {
        Queue::fake();
        Event::fake([TaskReminderDispatched::class, TaskBecameOverdue::class]);

        $assignee = TestUser::query()->create([
            'name' => 'SLA Runtime Assignee',
            'email' => 'sla-runtime@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_runtime_flow', (string) $assignee->getKey(), 'PT1H', [
            ['before_due' => 'PT30M', 'channel' => 'event'],
        ]);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-RUN-001']);
        $instance = DBFlow::start('sla_runtime_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        Carbon::setTestNow('2026-07-07 09:35:00');
        $this->artisan('dbflow:sla-dispatch')->assertSuccessful();
        Queue::assertPushed(ProcessSlaEventJob::class, 1);

        $reminder = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Reminder)
            ->firstOrFail();

        (new ProcessSlaEventJob((int) $reminder->getKey(), (int) $reminder->attempts))->handle(app(\DbflowLabs\Core\Services\Sla\ProcessSlaEvent::class));

        Event::assertDispatched(TaskReminderDispatched::class);
        $reminder->refresh();
        $this->assertSame(SlaEventStatus::Completed, $reminder->status);

        Carbon::setTestNow('2026-07-07 10:05:00');
        $this->artisan('dbflow:sla-dispatch')->assertSuccessful();

        $overdue = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Overdue)
            ->firstOrFail();

        $overdue->refresh();
        (new ProcessSlaEventJob((int) $overdue->getKey(), (int) $overdue->attempts))->handle(app(\DbflowLabs\Core\Services\Sla\ProcessSlaEvent::class));

        $task->refresh();
        $this->assertSame(WorkflowTaskStatus::Pending, $task->status);
        $this->assertNotNull($task->overdue_at);
        Event::assertDispatched(TaskBecameOverdue::class);

        $this->artisan('dbflow:process-timeouts')->assertSuccessful();
        $task->refresh();
        $this->assertSame(WorkflowTaskStatus::Pending, $task->status);
        $this->assertSame(0, WorkflowLog::query()->where('event', WorkflowLogEvent::TaskTimedOut->value)->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function approval_cancels_pending_sla_events(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Cancel Assignee',
            'email' => 'sla-cancel@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_cancel_flow', (string) $assignee->getKey(), 'PT4H');

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-CANCEL-001']);
        $instance = DBFlow::start('sla_cancel_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        DBFlow::approve($task, $assignee->getKey());

        $this->assertSame(
            0,
            WorkflowSlaEvent::query()
                ->where('workflow_task_id', $task->getKey())
                ->whereIn('status', [SlaEventStatus::Pending->value, SlaEventStatus::Processing->value])
                ->count(),
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function reassign_escalation_uses_escalation_provenance(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Escalation From',
            'email' => 'sla-esc-from@dbflow.dev',
        ]);
        $admin = TestUser::query()->create([
            'name' => 'SLA Escalation Admin',
            'email' => 'sla-esc-admin@dbflow.dev',
        ]);

        app(AssigneeResolverRegistry::class)->register(
            'workflow_admin',
            new class($admin) implements \DbflowLabs\Core\Contracts\AssigneeResolver {
                public function __construct(private readonly TestUser $admin) {}

                public function resolve(\DbflowLabs\Core\Models\WorkflowInstance $instance, array $node): array
                {
                    return [(string) $this->admin->getKey()];
                }
            },
        );

        Carbon::setTestNow('2026-07-07 09:00:00');

        $sla = [
            WorkflowDefinitionSchema::SLA_DUE_AFTER => 'PT1H',
            WorkflowDefinitionSchema::SLA_OVERDUE => [
                WorkflowDefinitionSchema::SLA_ESCALATION => [
                    WorkflowDefinitionSchema::SLA_TYPE => 'reassign',
                    WorkflowDefinitionSchema::SLA_TARGET => [
                        WorkflowDefinitionSchema::SLA_RESOLVER => 'permission',
                        WorkflowDefinitionSchema::SLA_VALUE => 'workflow_admin',
                    ],
                ],
            ],
        ];

        $this->publishWorkflowWithSla('sla_escalation_flow', (string) $assignee->getKey(), null, [], $sla);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-ESC-001']);
        $instance = DBFlow::start('sla_escalation_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        Carbon::setTestNow('2026-07-07 10:05:00');

        $overdue = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Overdue)
            ->firstOrFail();

        $overdue->forceFill([
            'status' => SlaEventStatus::Processing,
            'attempts' => 1,
            'processing_started_at' => now(),
        ])->save();

        (new ProcessSlaEventJob((int) $overdue->getKey(), 1))->handle(app(\DbflowLabs\Core\Services\Sla\ProcessSlaEvent::class));

        $escalation = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Escalation)
            ->firstOrFail();

        $escalation->forceFill([
            'status' => SlaEventStatus::Processing,
            'attempts' => 1,
            'processing_started_at' => now(),
        ])->save();

        (new ProcessSlaEventJob((int) $escalation->getKey(), 1))->handle(app(\DbflowLabs\Core\Services\Sla\ProcessSlaEvent::class));

        $escalation->refresh();
        $this->assertSame(SlaEventStatus::Completed, $escalation->status, (string) $escalation->last_error);

        $assignment = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('assignment_source', AssignmentSource::Escalation->value)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame((string) $admin->getKey(), $assignment->effectiveAssigneeUserId());

        Carbon::setTestNow();
    }

    #[Test]
    public function stale_recovery_returns_pending_without_dispatching_job(): void
    {
        Queue::fake();

        $assignee = TestUser::query()->create([
            'name' => 'SLA Recover Assignee',
            'email' => 'sla-recover@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_recover_flow', (string) $assignee->getKey(), 'PT1H');

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-RECOVER-001']);
        $instance = DBFlow::start('sla_recover_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $overdue = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Overdue)
            ->firstOrFail();

        $overdue->forceFill([
            'status' => SlaEventStatus::Processing,
            'attempts' => 1,
            'processing_started_at' => Carbon::parse('2026-07-07 08:00:00'),
            'max_attempts' => 3,
        ])->save();

        Carbon::setTestNow('2026-07-07 10:00:00');
        config(['dbflow.sla.stale_processing_threshold_seconds' => 60]);

        $this->artisan('dbflow:sla-recover')->assertSuccessful();

        $overdue->refresh();
        $this->assertSame(SlaEventStatus::Pending, $overdue->status);
        $this->assertSame(1, $overdue->attempts);
        Queue::assertNothingPushed();

        Carbon::setTestNow();
    }

    #[Test]
    public function retryable_failure_at_max_attempts_marks_failed_not_pending(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Retry Exhaust Assignee',
            'email' => 'sla-retry-exhaust@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_retry_exhaust_flow', (string) $assignee->getKey(), 'PT1H', [
            ['before_due' => 'PT30M', 'channel' => 'event'],
        ]);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-RETRY-001']);
        $instance = DBFlow::start('sla_retry_exhaust_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $reminder = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Reminder)
            ->firstOrFail();

        app(\DbflowLabs\Core\Services\Sla\SlaNotificationHandlerRegistry::class)->register(
            'event',
            new class implements \DbflowLabs\Core\Contracts\Sla\SlaNotificationHandler
            {
                public function handle(\DbflowLabs\Core\Contracts\Sla\SlaNotificationContext $context): \DbflowLabs\Core\Contracts\Sla\SlaHandlerResult
                {
                    return \DbflowLabs\Core\Contracts\Sla\SlaHandlerResult::retryable('temporary failure');
                }
            },
        );

        $reminder->forceFill([
            'status' => SlaEventStatus::Processing,
            'attempts' => 3,
            'max_attempts' => 3,
            'processing_started_at' => now(),
        ])->save();

        (new ProcessSlaEventJob((int) $reminder->getKey(), 3))->handle(app(\DbflowLabs\Core\Services\Sla\ProcessSlaEvent::class));

        $reminder->refresh();
        $this->assertSame(SlaEventStatus::Failed, $reminder->status);
        $this->assertFalse($reminder->isClaimable());

        Carbon::setTestNow();
    }

    #[Test]
    public function next_attempt_at_blocks_pending_retry_until_due(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Backoff Assignee',
            'email' => 'sla-backoff@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_backoff_flow', (string) $assignee->getKey(), 'PT1H');

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-BACKOFF-001']);
        $instance = DBFlow::start('sla_backoff_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $overdue = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Overdue)
            ->firstOrFail();

        $overdue->forceFill([
            'status' => SlaEventStatus::Pending,
            'attempts' => 1,
            'max_attempts' => 3,
            'scheduled_at' => Carbon::parse('2026-07-07 08:00:00'),
            'next_attempt_at' => Carbon::parse('2026-07-07 10:00:00'),
        ])->save();

        Carbon::setTestNow('2026-07-07 09:30:00');
        $result = app(\DbflowLabs\Core\Services\Sla\DispatchSlaEvents::class)->handle();
        $this->assertSame(0, $result['claimed']);

        Carbon::setTestNow('2026-07-07 10:00:00');
        $result = app(\DbflowLabs\Core\Services\Sla\DispatchSlaEvents::class)->handle();
        $this->assertSame(1, $result['claimed']);

        Carbon::setTestNow();
    }

    #[Test]
    public function non_retryable_failure_marks_failed_without_scheduling_retry(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Non Retry Assignee',
            'email' => 'sla-non-retry@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_non_retry_flow', (string) $assignee->getKey(), 'PT1H', [
            ['before_due' => 'PT30M', 'channel' => 'event'],
        ]);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-NON-RETRY-001']);
        $instance = DBFlow::start('sla_non_retry_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $reminder = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Reminder)
            ->firstOrFail();

        app(\DbflowLabs\Core\Services\Sla\SlaNotificationHandlerRegistry::class)->register(
            'event',
            new class implements \DbflowLabs\Core\Contracts\Sla\SlaNotificationHandler
            {
                public function handle(\DbflowLabs\Core\Contracts\Sla\SlaNotificationContext $context): \DbflowLabs\Core\Contracts\Sla\SlaHandlerResult
                {
                    return \DbflowLabs\Core\Contracts\Sla\SlaHandlerResult::failed('permanent failure');
                }
            },
        );

        $reminder->forceFill([
            'status' => SlaEventStatus::Processing,
            'attempts' => 1,
            'max_attempts' => 3,
            'processing_started_at' => now(),
        ])->save();

        (new ProcessSlaEventJob((int) $reminder->getKey(), 1))->handle(app(\DbflowLabs\Core\Services\Sla\ProcessSlaEvent::class));

        $reminder->refresh();
        $this->assertSame(SlaEventStatus::Failed, $reminder->status);
        $this->assertNull($reminder->next_attempt_at);

        Carbon::setTestNow();
    }

    #[Test]
    public function stale_recovery_exhausts_when_attempts_spent(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Exhaust Recover Assignee',
            'email' => 'sla-exhaust-recover@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_exhaust_recover_flow', (string) $assignee->getKey(), 'PT1H');

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-EXHAUST-RECOVER-001']);
        $instance = DBFlow::start('sla_exhaust_recover_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $overdue = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Overdue)
            ->firstOrFail();

        $overdue->forceFill([
            'status' => SlaEventStatus::Processing,
            'attempts' => 3,
            'max_attempts' => 3,
            'processing_started_at' => Carbon::parse('2026-07-07 08:00:00'),
        ])->save();

        Carbon::setTestNow('2026-07-07 10:00:00');
        config(['dbflow.sla.stale_processing_threshold_seconds' => 60]);

        $this->artisan('dbflow:sla-recover')->assertSuccessful();

        $overdue->refresh();
        $this->assertSame(SlaEventStatus::Failed, $overdue->status);

        Carbon::setTestNow();
    }

    #[Test]
    public function repeated_recovery_is_idempotent(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Recovery Idempotent Assignee',
            'email' => 'sla-recovery-idem@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_recovery_idem_flow', (string) $assignee->getKey(), 'PT1H');

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-RECOVERY-IDEM-001']);
        $instance = DBFlow::start('sla_recovery_idem_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $overdue = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Overdue)
            ->firstOrFail();

        $overdue->forceFill([
            'status' => SlaEventStatus::Processing,
            'attempts' => 1,
            'max_attempts' => 3,
            'processing_started_at' => Carbon::parse('2026-07-07 08:00:00'),
        ])->save();

        Carbon::setTestNow('2026-07-07 10:00:00');
        config(['dbflow.sla.stale_processing_threshold_seconds' => 60]);

        $first = app(\DbflowLabs\Core\Services\Sla\RecoverStaleSlaEvents::class)->handle();
        $second = app(\DbflowLabs\Core\Services\Sla\RecoverStaleSlaEvents::class)->handle();

        $this->assertSame(1, $first['recovered']);
        $this->assertSame(0, $second['recovered']);

        $overdue->refresh();
        $this->assertSame(SlaEventStatus::Pending, $overdue->status);

        Carbon::setTestNow();
    }

    #[Test]
    public function processing_cancels_when_task_becomes_terminal(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'SLA Terminal Race Assignee',
            'email' => 'sla-terminal-race@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');
        $this->publishWorkflowWithSla('sla_terminal_race_flow', (string) $assignee->getKey(), 'PT1H');

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SLA-TERMINAL-RACE-001']);
        $instance = DBFlow::start('sla_terminal_race_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $overdue = WorkflowSlaEvent::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event_type', SlaEventType::Overdue)
            ->firstOrFail();

        $overdue->forceFill([
            'status' => SlaEventStatus::Processing,
            'attempts' => 1,
            'max_attempts' => 3,
            'processing_started_at' => now(),
        ])->save();

        DBFlow::approve($task, $assignee->getKey());

        (new ProcessSlaEventJob((int) $overdue->getKey(), 1))->handle(app(\DbflowLabs\Core\Services\Sla\ProcessSlaEvent::class));

        $overdue->refresh();
        $this->assertSame(SlaEventStatus::Cancelled, $overdue->status);

        Carbon::setTestNow();
    }

    /**
     * @param  list<array{before_due: string, channel: string}>  $reminders
     * @param  array<string, mixed>|null  $slaOverride
     */
    private function publishWorkflowWithSla(
        string $key,
        string $assigneeValue,
        ?string $dueAfter = 'PT24H',
        array $reminders = [],
        ?array $slaOverride = null,
    ): void {
        $definition = $this->workflowDefinitionWithSla($dueAfter, $reminders, $slaOverride, $key, $assigneeValue);
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);
    }

    /**
     * @param  list<array{before_due: string, channel: string}>  $reminders
     * @param  array<string, mixed>|null  $slaOverride
     * @return array<string, mixed>
     */
    private function workflowDefinitionWithSla(
        ?string $dueAfter,
        array $reminders = [],
        ?array $slaOverride = null,
        string $key = 'sla_flow',
        string $assigneeValue = '1',
    ): array {
        $factory = app(WorkflowBuilderNodeFactory::class);

        $nodes = [
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_START, 'start', 'Start'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_APPROVAL, 'approval', 'Approval'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_END, 'end', 'End'),
        ];

        $nodes[1]['config'][WorkflowDefinitionSchema::CONFIG_ASSIGNEES] = [
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => $assigneeValue,
        ];

        $sla = $slaOverride ?? [
            WorkflowDefinitionSchema::SLA_DUE_AFTER => $dueAfter,
            WorkflowDefinitionSchema::SLA_REMINDERS => array_map(
                static fn (array $reminder, int $index): array => [
                    WorkflowDefinitionSchema::SLA_BEFORE_DUE => $reminder['before_due'],
                    WorkflowDefinitionSchema::SLA_CHANNEL => $reminder['channel'],
                    WorkflowDefinitionSchema::SLA_SEQUENCE => $index + 1,
                ],
                $reminders,
                array_keys($reminders),
            ),
        ];

        $nodes[1]['config'][WorkflowDefinitionSchema::CONFIG_SLA] = $sla;

        return [
            WorkflowDefinitionSchema::FIELD_KEY => $key,
            WorkflowDefinitionSchema::FIELD_NAME => 'SLA Flow',
            WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION => '1.1',
            WorkflowDefinitionSchema::FIELD_NODES => $nodes,
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'start',
                    WorkflowDefinitionSchema::FIELD_TO => 'approval',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'approval',
                    WorkflowDefinitionSchema::FIELD_TO => 'end',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
            ],
        ];
    }
}
