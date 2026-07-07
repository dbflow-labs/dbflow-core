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
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Exceptions\UserCannotApproveTaskException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Tests\Concerns\LoadsBlueprintFixtures;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SequentialApprovalFlowTest extends TestCase
{
    use LoadsBlueprintFixtures;
    use RegistersEngineTestResources;

    #[Test]
    public function sequential_multi_level_approval_advances_through_each_gate(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $definition = $this->loadBlueprintFixture('sequential_multi_level_approval');
        $definition = $this->patchAssigneeUserId($definition, 'director_approval', (int) $users['director']->getKey());

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SEQ-001']);

        $instance = DBFlow::start('sequential_multi_level_approval', $subject, $users['first']->getKey());

        $this->assertSame(WorkflowInstanceStatus::Running, $instance->status);
        $this->assertSame('manager_approval', $instance->current_node_key);

        $managerTask = $this->pendingTaskForNode($instance, 'manager_approval');
        $this->assertSame(ApprovalMode::Sequential, $managerTask->approval_mode);

        $assignments = WorkflowTaskAssignment::query()
            ->where('workflow_task_id', $managerTask->getKey())
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $assignments);
        $this->assertTrue($assignments[0]->status === WorkflowTaskAssignmentStatus::Pending);
        $this->assertTrue($assignments[1]->status === WorkflowTaskAssignmentStatus::Pending);

        DBFlow::approve($managerTask, $users['first']->getKey());
        $managerTask->refresh();
        $this->assertSame(WorkflowTaskStatus::Pending, $managerTask->status);

        DBFlow::approve($managerTask, $users['second']->getKey());
        $instance->refresh();

        $this->assertSame('director_approval', $instance->current_node_key);

        $directorTask = $this->pendingTaskForNode($instance, 'director_approval');
        DBFlow::approve($directorTask, $users['director']->getKey());

        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end_approved', $instance->current_node_key);
        $this->assertNotNull($instance->completed_at);

        $this->assertTrue(
            WorkflowLog::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('event', WorkflowLogEvent::WorkflowStarted)
                ->exists(),
        );

        $this->assertTrue(
            WorkflowLog::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('event', WorkflowLogEvent::WorkflowCompleted)
                ->exists(),
        );

        $this->assertSame(
            2,
            WorkflowLog::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('event', WorkflowLogEvent::TaskCreated)
                ->count(),
        );
    }

    #[Test]
    public function later_sequential_assignee_cannot_approve_out_of_order(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $definition = $this->loadBlueprintFixture('sequential_multi_level_approval');
        $definition = $this->patchAssigneeUserId($definition, 'director_approval', (int) $users['director']->getKey());

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SEQ-OOO-001']);

        $instance = DBFlow::start('sequential_multi_level_approval', $subject, $users['first']->getKey());
        $managerTask = $this->pendingTaskForNode($instance, 'manager_approval');

        $this->expectException(UserCannotApproveTaskException::class);

        DBFlow::approve($managerTask, $users['second']->getKey());
    }

    #[Test]
    public function later_sequential_assignee_cannot_reject_out_of_order(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $definition = $this->loadBlueprintFixture('sequential_multi_level_approval');
        $definition = $this->patchAssigneeUserId($definition, 'director_approval', (int) $users['director']->getKey());

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SEQ-OOO-002']);

        $instance = DBFlow::start('sequential_multi_level_approval', $subject, $users['first']->getKey());
        $managerTask = $this->pendingTaskForNode($instance, 'manager_approval');

        $this->expectException(UserCannotApproveTaskException::class);

        DBFlow::reject($managerTask, $users['second']->getKey());
    }

    #[Test]
    public function pending_tasks_query_only_surfaces_current_sequential_assignee(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $definition = $this->loadBlueprintFixture('sequential_multi_level_approval');
        $definition = $this->patchAssigneeUserId($definition, 'director_approval', (int) $users['director']->getKey());

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'SEQ-QUERY-001']);

        DBFlow::start('sequential_multi_level_approval', $subject, $users['first']->getKey());

        $queryService = app(\DbflowLabs\Core\Services\WorkflowTaskQueryService::class);

        $this->assertSame(1, $queryService->countPendingTasksForUser((string) $users['first']->getKey()));
        $this->assertSame(0, $queryService->countPendingTasksForUser((string) $users['second']->getKey()));
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

            $definition['nodes'][$index]['config']['assignees']['type'] = 'user';
            $definition['nodes'][$index]['config']['assignees']['value'] = (string) $userId;
        }

        return $definition;
    }
}
