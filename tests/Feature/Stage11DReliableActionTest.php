<?php

declare(strict_types=1);

namespace DbflowLabs\Core\Tests\Feature;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\Capabilities\RuntimeCapabilityRegistry;
use DbflowLabs\Core\Contracts\Actions\ReliableActionHandler;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Enums\ActionExecutionStatus;
use DbflowLabs\Core\Enums\RuntimeCapability;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Jobs\ProcessActionExecutionJob;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\Actions\DispatchActionExecutions;
use DbflowLabs\Core\Services\Actions\ManualRetryActionExecution;
use DbflowLabs\Core\Services\Actions\ManualSkipActionExecution;
use DbflowLabs\Core\Services\Actions\ProcessActionExecution;
use DbflowLabs\Core\Services\Actions\RecoverStaleActionExecutions;
use DbflowLabs\Core\Services\Actions\ReliableActionHandlerRegistry;
use DbflowLabs\Core\Support\WorkflowBuilderNodeFactory;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\ReliableRecordingActionHandler;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

final class Stage11DReliableActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        ReliableRecordingActionHandler::reset();

        app(ReliableActionHandlerRegistry::class)->register(
            'reliable_record',
            app(ReliableRecordingActionHandler::class),
        );
    }

    #[Test]
    public function reliable_definition_requires_capability_when_disabled(): void
    {
        app(RuntimeCapabilityRegistry::class)->disable(RuntimeCapability::ReliableAction);

        $result = app(WorkflowDefinitionValidator::class)->validate(
            $this->workflowDefinition(ActionExecutionMode::ReliableBlocking),
        );

        $this->assertFalse($result->isValid());
        $this->assertSame('missing_runtime_capability', $result->errors()[0]['code']);
    }

    #[Test]
    public function stop_on_error_conflicts_with_reliable_mode(): void
    {
        $definition = $this->workflowDefinition(ActionExecutionMode::ReliableBlocking);
        $definition['nodes'][1]['config']['stop_on_error'] = true;

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);

        $this->assertFalse($result->isValid());
        $this->assertSame('invalid_value', $result->errors()[0]['code']);
    }

    #[Test]
    public function legacy_sync_action_does_not_create_execution_rows(): void
    {
        $assignee = $this->seedAssignee();
        $this->publishWorkflow(ActionExecutionMode::LegacySync, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'LEGACY-ACT-001']);
        DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());

        $this->assertSame(0, WorkflowActionExecution::query()->count());
    }

    #[Test]
    public function blocking_action_stays_at_node_until_success_then_advances(): void
    {
        $assignee = $this->seedAssignee();
        $this->publishWorkflow(ActionExecutionMode::ReliableBlocking, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'BLOCK-ACT-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());

        $this->assertSame('reliable_action', $instance->current_node_key);
        $this->assertSame(WorkflowInstanceStatus::Running, $instance->status);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_instance_id', $instance->getKey())->count());

        $execution = WorkflowActionExecution::query()->firstOrFail();
        $this->assertSame(ActionExecutionStatus::Running, $execution->status);

        Queue::assertPushed(ProcessActionExecutionJob::class);

        $this->processExecutionJob($execution);

        $instance->refresh();
        $this->assertSame(1, ReliableRecordingActionHandler::$callCount);
        $this->assertSame(ActionExecutionStatus::Succeeded, $execution->refresh()->status);
        $this->assertNotNull($execution->workflow_advanced_at);
        $this->assertSame('approval', $instance->current_node_key);
        $this->assertSame(1, WorkflowTask::query()->where('workflow_instance_id', $instance->getKey())->count());
    }

    #[Test]
    public function non_blocking_action_advances_before_handler_success(): void
    {
        $assignee = $this->seedAssignee();
        $definition = $this->workflowDefinition(ActionExecutionMode::ReliableNonBlocking);
        $definition['nodes'] = array_values(array_filter(
            $definition['nodes'],
            static fn (array $node): bool => $node['key'] !== 'approval',
        ));
        $definition['transitions'] = [
            ['from' => 'start', 'to' => 'reliable_action'],
            ['from' => 'reliable_action', 'to' => 'end'],
        ];
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'NONBLOCK-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());

        $this->assertSame('end', $instance->current_node_key);
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame(1, WorkflowActionExecution::query()->count());
        $this->assertSame(0, ReliableRecordingActionHandler::$callCount);

        $execution = WorkflowActionExecution::query()->firstOrFail();
        $this->assertSame(ActionExecutionStatus::Running, $execution->status);
        $this->processExecutionJob($execution);

        $this->assertSame(1, ReliableRecordingActionHandler::$callCount);
        $this->assertSame(ActionExecutionStatus::Succeeded, $execution->refresh()->status);
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->refresh()->status);
    }

    #[Test]
    public function retryable_failure_schedules_retry_and_exhausts(): void
    {
        $assignee = $this->seedAssignee();
        $definition = $this->workflowDefinition(ActionExecutionMode::ReliableBlocking);
        $definition['nodes'][1]['config']['payload'] = ['behavior' => 'retryable'];
        $definition['nodes'][1]['config']['max_attempts'] = 2;
        $definition['nodes'][1]['config']['retry'] = ['max_attempts' => 2, 'backoff_seconds' => 0];
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        Carbon::setTestNow('2026-07-08 10:00:00');
        $subject = ContextTestSubject::query()->create(['reference_code' => 'RETRY-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        $this->processExecutionJob($execution);
        $execution->refresh();
        $this->assertSame(ActionExecutionStatus::Queued, $execution->status);
        $this->assertSame(1, $execution->attempts);

        app(DispatchActionExecutions::class)->handle();
        $execution->refresh();
        $this->processExecutionJob($execution);
        $execution->refresh();

        $this->assertSame(ActionExecutionStatus::Exhausted, $execution->status);
        $this->assertSame('reliable_action', $instance->refresh()->current_node_key);
        $this->assertTrue(
            WorkflowLog::query()->where('event', WorkflowLogEvent::ActionExecutionExhausted->value)->exists(),
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function duplicate_queue_consumption_does_not_double_advance(): void
    {
        $assignee = $this->seedAssignee();
        $this->publishWorkflow(ActionExecutionMode::ReliableBlocking, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'IDEM-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        $this->processExecutionJob($execution);
        $firstAdvancedAt = $execution->refresh()->workflow_advanced_at;

        (new ProcessActionExecutionJob((int) $execution->getKey(), (int) $execution->attempts))
            ->handle(app(ProcessActionExecution::class));

        $this->assertSame($firstAdvancedAt?->toIso8601String(), $execution->refresh()->workflow_advanced_at?->toIso8601String());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_instance_id', $instance->getKey())->count());
    }

    #[Test]
    public function manual_retry_and_skip_work_for_blocking_executions(): void
    {
        $assignee = $this->seedAssignee();
        $definition = $this->workflowDefinition(ActionExecutionMode::ReliableBlocking);
        $definition['nodes'][1]['config']['payload'] = ['behavior' => 'failed'];
        $definition['nodes'][1]['config']['allow_manual_skip'] = true;
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'MANUAL-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        $this->processExecutionJob($execution);
        $this->assertSame(ActionExecutionStatus::Failed, $execution->refresh()->status);

        app(ManualRetryActionExecution::class)->handle($execution->refresh(), $assignee->getKey(), 'operator retry');
        $execution->refresh();
        $this->assertSame(ActionExecutionStatus::Running, $execution->status);
        $this->assertSame(1, $execution->attempts);

        $execution->forceFill([
            'status' => ActionExecutionStatus::Failed,
            'failed_at' => now(),
        ])->save();

        app(ManualSkipActionExecution::class)->handle($execution->refresh(), $assignee->getKey(), 'operator skip');
        $execution->refresh();

        $this->assertSame(ActionExecutionStatus::Skipped, $execution->status);
        $this->assertNotNull($execution->workflow_advanced_at);
        $this->assertSame('approval', $instance->refresh()->current_node_key);
    }

    #[Test]
    public function manual_skip_rejects_running_executions(): void
    {
        $assignee = $this->seedAssignee();
        $definition = $this->workflowDefinition(ActionExecutionMode::ReliableBlocking);
        $definition['nodes'][1]['config']['allow_manual_skip'] = true;
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SKIP-RUNNING-001']);
        DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        app(ManualSkipActionExecution::class)->handle($execution->refresh(), $assignee->getKey(), 'too early');
    }

    #[Test]
    public function stale_running_execution_is_recovered(): void
    {
        $assignee = $this->seedAssignee();
        $this->publishWorkflow(ActionExecutionMode::ReliableBlocking, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'RECOVER-001']);
        DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        Carbon::setTestNow('2026-07-08 12:00:00');
        $execution->forceFill([
            'status' => ActionExecutionStatus::Running,
            'attempts' => 1,
            'max_attempts' => 3,
            'processing_started_at' => Carbon::parse('2026-07-08 10:00:00'),
        ])->save();

        $result = app(RecoverStaleActionExecutions::class)->handle();
        $this->assertSame(1, $result['recovered']);
        $this->assertSame(ActionExecutionStatus::Queued, $execution->refresh()->status);

        Carbon::setTestNow();
    }

  #[Test]
    public function cancel_workflow_cancels_queued_action_executions(): void
    {
        $assignee = $this->seedAssignee();
        $this->publishWorkflow(ActionExecutionMode::ReliableBlocking, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'CANCEL-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());

        $execution = WorkflowActionExecution::query()->firstOrFail();
        $this->assertSame(ActionExecutionStatus::Running, $execution->status);

        DBFlow::cancel($instance, $assignee->getKey());

        $execution = WorkflowActionExecution::query()->firstOrFail();
        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->status);
    }

    #[Test]
    public function success_racing_with_cancellation_does_not_advance_workflow(): void
    {
        $assignee = $this->seedAssignee();

        app(ReliableActionHandlerRegistry::class)->register(
            'reliable_record',
            new class($assignee) implements ReliableActionHandler
            {
                public function __construct(private readonly TestUser $actor) {}

                public function handle(\DbflowLabs\Core\Contracts\Actions\ReliableActionContext $context): \DbflowLabs\Core\Contracts\Actions\ReliableActionResult
                {
                    DBFlow::cancel($context->instance, $this->actor->getKey());

                    return \DbflowLabs\Core\Contracts\Actions\ReliableActionResult::successful(['cancelled_during_handler' => true]);
                }
            },
        );

        $this->publishWorkflow(ActionExecutionMode::ReliableBlocking, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'RACE-SUCCESS-CANCEL-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        (new ProcessActionExecutionJob((int) $execution->getKey(), (int) $execution->attempts))
            ->handle(app(ProcessActionExecution::class));

        $execution->refresh();
        $instance->refresh();

        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->status);
        $this->assertNull($execution->workflow_advanced_at);
        $this->assertSame(WorkflowInstanceStatus::Cancelled, $instance->status);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_instance_id', $instance->getKey())->count());
    }

    #[Test]
    public function delayed_success_is_ignored_after_cancellation(): void
    {
        $assignee = $this->seedAssignee();
        $this->publishWorkflow(ActionExecutionMode::ReliableBlocking, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'RACE-CANCEL-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        DBFlow::cancel($instance, $assignee->getKey());
        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->refresh()->status);

        (new ProcessActionExecutionJob((int) $execution->getKey(), (int) $execution->attempts))
            ->handle(app(ProcessActionExecution::class));

        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->refresh()->status);
        $this->assertNull($execution->workflow_advanced_at);
    }

    #[Test]
    public function duplicate_delivery_after_success_does_not_reprocess(): void
    {
        $assignee = $this->seedAssignee();
        $this->publishWorkflow(ActionExecutionMode::ReliableBlocking, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'RACE-DUP-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        $this->processExecutionJob($execution);
        $this->assertSame(ActionExecutionStatus::Succeeded, $execution->refresh()->status);
        $advancedAt = $execution->workflow_advanced_at?->toIso8601String();

        (new ProcessActionExecutionJob((int) $execution->getKey(), (int) $execution->attempts))
            ->handle(app(ProcessActionExecution::class));

        $this->assertSame($advancedAt, $execution->refresh()->workflow_advanced_at?->toIso8601String());
        $this->assertSame(1, ReliableRecordingActionHandler::$callCount);
        $this->assertSame(1, WorkflowTask::query()->where('workflow_instance_id', $instance->getKey())->count());
    }

    #[Test]
    public function recovery_preserves_logical_execution_key(): void
    {
        $assignee = $this->seedAssignee();
        $this->publishWorkflow(ActionExecutionMode::ReliableBlocking, (string) $assignee->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'RACE-KEY-001']);
        DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();
        $originalKey = $execution->logical_execution_key;

        Carbon::setTestNow('2026-07-08 12:00:00');
        $execution->forceFill([
            'status' => ActionExecutionStatus::Running,
            'attempts' => 1,
            'max_attempts' => 3,
            'processing_started_at' => Carbon::parse('2026-07-08 10:00:00'),
        ])->save();

        app(RecoverStaleActionExecutions::class)->handle();
        $execution->refresh();

        $this->assertSame(ActionExecutionStatus::Queued, $execution->status);
        $this->assertSame($originalKey, $execution->logical_execution_key);

        Carbon::setTestNow();
    }

    #[Test]
    public function non_blocking_stale_recovery_survives_completed_instance(): void
    {
        $assignee = $this->seedAssignee();
        $definition = $this->workflowDefinition(ActionExecutionMode::ReliableNonBlocking);
        $definition['nodes'] = array_values(array_filter(
            $definition['nodes'],
            static fn (array $node): bool => $node['key'] !== 'approval',
        ));
        $definition['transitions'] = [
            ['from' => 'start', 'to' => 'reliable_action'],
            ['from' => 'reliable_action', 'to' => 'end'],
        ];
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'NB-RECOVER-001']);
        $instance = DBFlow::start('reliable_action_flow', $subject, $assignee->getKey());
        $execution = WorkflowActionExecution::query()->firstOrFail();

        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);

        Carbon::setTestNow('2026-07-08 12:00:00');
        $execution->forceFill([
            'status' => ActionExecutionStatus::Running,
            'attempts' => 1,
            'max_attempts' => 3,
            'processing_started_at' => Carbon::parse('2026-07-08 10:00:00'),
        ])->save();

        $result = app(RecoverStaleActionExecutions::class)->handle();
        $this->assertSame(1, $result['recovered']);
        $this->assertSame(0, $result['cancelled']);
        $this->assertSame(ActionExecutionStatus::Queued, $execution->refresh()->status);

        Carbon::setTestNow();
    }

    private function seedAssignee(): TestUser
    {
        return TestUser::query()->create([
            'name' => 'Reliable Action Assignee',
            'email' => 'reliable-action@dbflow.dev',
        ]);
    }

    private function publishWorkflow(ActionExecutionMode $mode, string $assigneeId): void
    {
        $workflow = app(CreateWorkflowDraft::class)->handle($this->workflowDefinition($mode, $assigneeId), 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowDefinition(ActionExecutionMode $mode, string $assigneeId = '1'): array
    {
        $factory = app(WorkflowBuilderNodeFactory::class);

        $nodes = [
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_START, 'start', 'Start'),
            [
                'key' => 'reliable_action',
                'type' => 'action',
                'name' => 'Reliable Action',
                'config' => [
                    'action_key' => 'reliable_record',
                    'execution_mode' => $mode->value,
                    'max_attempts' => 3,
                    'retry' => ['max_attempts' => 3, 'backoff_seconds' => 0],
                ],
            ],
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_APPROVAL, 'approval', 'Approval'),
            $factory->make(WorkflowDefinitionSchema::NODE_TYPE_END, 'end', 'End'),
        ];

        if ($mode === ActionExecutionMode::LegacySync) {
            unset($nodes[1]['config']['execution_mode'], $nodes[1]['config']['max_attempts'], $nodes[1]['config']['retry']);
            $nodes[1]['config']['action_key'] = 'log';
        }

        $nodes[2]['config'][WorkflowDefinitionSchema::CONFIG_ASSIGNEES] = [
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => $assigneeId,
        ];

        return [
            WorkflowDefinitionSchema::FIELD_KEY => 'reliable_action_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'Reliable Action Flow',
            WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION => '1.1',
            WorkflowDefinitionSchema::FIELD_NODES => $nodes,
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                ['from' => 'start', 'to' => 'reliable_action'],
                ['from' => 'reliable_action', 'to' => 'approval'],
                ['from' => 'approval', 'to' => 'end'],
            ],
        ];
    }

    private function processExecutionJob(WorkflowActionExecution $execution): void
    {
        $execution->refresh();

        if ($execution->status === ActionExecutionStatus::Queued) {
            app(DispatchActionExecutions::class)->dispatchExecution((int) $execution->getKey());
            $execution->refresh();
        }

        (new ProcessActionExecutionJob((int) $execution->getKey(), (int) $execution->attempts))
            ->handle(app(ProcessActionExecution::class));
    }
}
