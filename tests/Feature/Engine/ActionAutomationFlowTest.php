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

use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\ActionFailed;
use DbflowLabs\Core\Exceptions\ActionExecutionFailedException;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Support\ActionManager;
use DbflowLabs\Core\Tests\Concerns\LoadsBlueprintFixtures;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\RecordingActionHandler;
use DbflowLabs\Core\Tests\Fixtures\ThrowingActionHandler;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ActionAutomationFlowTest extends TestCase
{
    use LoadsBlueprintFixtures;
    use RegistersEngineTestResources;

    #[Test]
    public function action_node_runs_before_auto_exit_when_review_not_required(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerRecordingActionHandler();

        $definition = $this->loadBlueprintFixture('action_automation');
        $definition = $this->patchAssigneeUserId($definition, 'compliance_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'ACT-AUTO-001'])
            ->withWorkflowVariables(['requires_review' => false]);

        $instance = DBFlow::start('action_automation', $subject, $users['first']->getKey());

        $this->assertSame(1, RecordingActionHandler::$callCount);
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end_auto', $instance->current_node_key);
        $this->assertSame(
            0,
            WorkflowTask::query()->where('workflow_instance_id', $instance->getKey())->count(),
        );
        $this->assertTrue(
            WorkflowLog::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('event', WorkflowLogEvent::ActionExecuted)
                ->exists(),
        );
    }

    #[Test]
    public function action_node_runs_before_manual_review_path_when_review_required(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerRecordingActionHandler();

        $definition = $this->loadBlueprintFixture('action_automation');
        $definition = $this->patchAssigneeUserId($definition, 'compliance_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'ACT-MANUAL-001'])
            ->withWorkflowVariables(['requires_review' => true]);

        $instance = DBFlow::start('action_automation', $subject, $users['first']->getKey());

        $this->assertSame(1, RecordingActionHandler::$callCount);
        $this->assertSame(WorkflowInstanceStatus::Running, $instance->status);
        $this->assertSame('compliance_review', $instance->current_node_key);

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('node_key', 'compliance_review')
            ->where('status', WorkflowTaskStatus::Pending)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $task);

        DBFlow::approve($task, $users['first']->getKey());

        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end_manual', $instance->current_node_key);
    }

    #[Test]
    public function failing_action_node_dispatches_action_failed_and_workflow_continues_by_default(): void
    {
        Event::fake([ActionFailed::class]);

        $users = $this->seedEngineUsers();
        ThrowingActionHandler::reset();
        app(ActionManager::class)->register('throwing_action', ThrowingActionHandler::class);

        $definition = $this->loadBlueprintFixture('action_automation');
        $definition['nodes'][1]['config']['action_key'] = 'throwing_action';
        $definition = $this->patchAssigneeUserId($definition, 'compliance_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'ACT-FAIL-001'])
            ->withWorkflowVariables(['requires_review' => false]);

        $instance = DBFlow::start('action_automation', $subject, $users['first']->getKey());

        $this->assertSame(1, ThrowingActionHandler::$callCount);
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end_auto', $instance->current_node_key);

        $this->assertTrue(
            WorkflowLog::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('event', WorkflowLogEvent::ActionFailed)
                ->exists(),
        );

        Event::assertDispatched(ActionFailed::class);
    }

    #[Test]
    public function stop_on_error_action_node_aborts_traversal_instead_of_continuing(): void
    {
        Event::fake([ActionFailed::class]);

        $users = $this->seedEngineUsers();
        ThrowingActionHandler::reset();
        app(ActionManager::class)->register('throwing_action', ThrowingActionHandler::class);

        $definition = $this->loadBlueprintFixture('action_automation');
        $definition['nodes'][1]['config']['action_key'] = 'throwing_action';
        $definition['nodes'][1]['config']['stop_on_error'] = true;
        $definition = $this->patchAssigneeUserId($definition, 'compliance_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'ACT-STOP-001'])
            ->withWorkflowVariables(['requires_review' => false]);

        $this->expectException(ActionExecutionFailedException::class);

        try {
            DBFlow::start('action_automation', $subject, $users['first']->getKey());
        } finally {
            Event::assertDispatched(ActionFailed::class);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function publishDefinition(array $definition): void
    {
        $workflow = app(\DbflowLabs\Core\Actions\CreateWorkflowDraft::class)->handle($definition, 1);
        app(\DbflowLabs\Core\Actions\PublishWorkflowDraft::class)->handle($workflow, 1);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function patchAssigneeUserId(array $definition, string $nodeKey, int $userId): array
    {
        foreach ($definition['nodes'] as $index => $node) {
            if (! is_array($node) || ($node['key'] ?? null) !== $nodeKey) {
                continue;
            }

            $definition['nodes'][$index]['config']['assignees']['value'] = (string) $userId;
        }

        return $definition;
    }
}
