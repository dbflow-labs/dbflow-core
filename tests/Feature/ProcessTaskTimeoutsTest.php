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
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\TaskTimedOut;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Support\WorkflowBuilderNodeFactory;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\TestUser;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\BlueprintValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ProcessTaskTimeoutsTest extends TestCase
{
    #[Test]
    public function task_created_with_due_at_when_node_has_timeout(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Timeout Assignee',
            'email' => 'timeout-assignee@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');

        $this->publishWorkflowWithTimeout(
            'timeout_due_at_flow',
            (string) $assignee->getKey(),
            dueIn: 'PT2H',
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'TIMEOUT-DUE-001']);
        $instance = DBFlow::start('timeout_due_at_flow', $subject, $assignee->getKey());

        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        $this->assertNotNull($task->due_at);
        $this->assertSame('2026-07-07 11:00:00', $task->due_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    #[Test]
    public function overdue_task_without_on_timeout_is_logged_but_stays_pending(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Audit Only Assignee',
            'email' => 'audit-only@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');

        $this->publishWorkflowWithTimeout(
            'timeout_audit_only_flow',
            (string) $assignee->getKey(),
            dueIn: 'PT1H',
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'TIMEOUT-AUDIT-001']);
        $instance = DBFlow::start('timeout_audit_only_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        Carbon::setTestNow('2026-07-07 11:00:00');
        Event::fake([TaskTimedOut::class]);

        $this->artisan('dbflow:process-timeouts')->assertSuccessful();

        $task->refresh();
        $this->assertSame(WorkflowTaskStatus::Pending, $task->status);
        $this->assertSame(1, WorkflowLog::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event', WorkflowLogEvent::TaskTimedOut->value)
            ->count());

        Event::assertDispatched(TaskTimedOut::class);

        $this->artisan('dbflow:process-timeouts')->assertSuccessful();
        $this->assertSame(1, WorkflowLog::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event', WorkflowLogEvent::TaskTimedOut->value)
            ->count());

        DBFlow::approve($task->fresh(), $assignee->getKey());
        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);

        Carbon::setTestNow();
    }

    #[Test]
    public function overdue_task_with_reject_end_enters_rejected_terminal_state(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Reject End Assignee',
            'email' => 'reject-end@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');

        $this->publishWorkflowWithTimeout(
            'timeout_reject_end_flow',
            (string) $assignee->getKey(),
            dueIn: 'PT1H',
            onTimeout: 'reject_end',
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'TIMEOUT-REJECT-001']);
        $instance = DBFlow::start('timeout_reject_end_flow', $subject, $assignee->getKey());
        $task = $instance->tasks()->where('status', WorkflowTaskStatus::Pending)->firstOrFail();

        Carbon::setTestNow('2026-07-07 11:00:00');
        Event::fake([TaskTimedOut::class]);

        $this->artisan('dbflow:process-timeouts')->assertSuccessful();

        $instance->refresh();
        $task->refresh();

        $this->assertSame(WorkflowInstanceStatus::Rejected, $instance->status);
        $this->assertSame(WorkflowTaskStatus::Rejected, $task->status);
        $this->assertSame(1, WorkflowLog::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event', WorkflowLogEvent::TaskTimedOut->value)
            ->count());
        $this->assertSame(1, WorkflowLog::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event', WorkflowLogEvent::TaskRejected->value)
            ->count());

        $timedOutLog = WorkflowLog::query()
            ->where('workflow_task_id', $task->getKey())
            ->where('event', WorkflowLogEvent::TaskTimedOut->value)
            ->firstOrFail();

        $this->assertNull($timedOutLog->actor_user_id);
        $this->assertSame('reject_end', $timedOutLog->payload['on_timeout'] ?? null);

        Carbon::setTestNow();
    }

    #[Test]
    public function non_overdue_tasks_are_not_processed(): void
    {
        $assignee = TestUser::query()->create([
            'name' => 'Future Due Assignee',
            'email' => 'future-due@dbflow.dev',
        ]);

        Carbon::setTestNow('2026-07-07 09:00:00');

        $this->publishWorkflowWithTimeout(
            'timeout_future_flow',
            (string) $assignee->getKey(),
            dueIn: 'P1D',
            onTimeout: 'reject_end',
        );

        $subject = ContextTestSubject::query()->create(['reference_code' => 'TIMEOUT-FUTURE-001']);
        DBFlow::start('timeout_future_flow', $subject, $assignee->getKey());

        Carbon::setTestNow('2026-07-07 12:00:00');

        $this->artisan('dbflow:process-timeouts')
            ->assertSuccessful()
            ->expectsOutput('Processed 0 timed-out task(s).');

        $this->assertSame(0, WorkflowLog::query()
            ->where('event', WorkflowLogEvent::TaskTimedOut->value)
            ->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function validate_rejects_invalid_timeout_configuration(): void
    {
        $definition = $this->workflowDefinitionWithTimeout(
            'timeout_invalid_registry_flow',
            '1',
            dueIn: 'not-a-duration',
        );

        $result = (new BlueprintValidator)->validateArray($definition);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());

        DBFlow::registerDefinitionProvider(
            app(\DbflowLabs\Core\Services\WorkflowDefinitionRegistry::class),
            new TimeoutTestWorkflowDefinitionProvider('timeout_invalid_registry_flow', $definition),
        );

        $this->artisan('dbflow:validate', ['--source' => 'registry'])
            ->assertFailed();
    }

    private function publishWorkflowWithTimeout(
        string $key,
        string $assigneeValue,
        string $dueIn,
        ?string $onTimeout = null,
    ): void {
        $definition = $this->workflowDefinitionWithTimeout($key, $assigneeValue, $dueIn, $onTimeout);
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowDefinitionWithTimeout(
        string $key,
        string $assigneeValue,
        string $dueIn,
        ?string $onTimeout = null,
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

        $timeout = [
            WorkflowDefinitionSchema::TIMEOUT_DUE_IN => $dueIn,
        ];

        if ($onTimeout !== null) {
            $timeout[WorkflowDefinitionSchema::TIMEOUT_ON_TIMEOUT] = $onTimeout;
        }

        $nodes[1]['config'][WorkflowDefinitionSchema::CONFIG_TIMEOUT] = $timeout;

        return [
            WorkflowDefinitionSchema::FIELD_KEY => $key,
            WorkflowDefinitionSchema::FIELD_NAME => 'Timeout Flow',
            WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION => '1.0',
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

/**
 * @internal
 */
final class TimeoutTestWorkflowDefinitionProvider implements \DbflowLabs\Core\Contracts\WorkflowDefinitionProvider
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(
        private readonly string $key,
        private readonly array $definition,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function definition(): array
    {
        return $this->definition;
    }
}
