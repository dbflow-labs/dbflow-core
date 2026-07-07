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
use DbflowLabs\Core\Enums\RejectStrategy;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Exceptions\InvalidWorkflowTopologyException;
use DbflowLabs\Core\Exceptions\TaskNotPendingException;
use DbflowLabs\Core\Exceptions\UserCannotApproveTaskException;
use DbflowLabs\Core\Exceptions\WorkflowAlreadyRunningException;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Tests\Concerns\LoadsBlueprintFixtures;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Validation\BlueprintValidator;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class WorkflowExecutionEdgeCasesTest extends TestCase
{
    use LoadsBlueprintFixtures;
    use RegistersEngineTestResources;

    #[Test]
    public function starting_the_same_workflow_twice_throws_already_running(): void
    {
        $users = $this->seedEngineUsers();
        $this->registerSequentialTeamResolver([
            (int) $users['first']->getKey(),
            (int) $users['second']->getKey(),
        ]);

        $definition = $this->loadBlueprintFixture('sequential_multi_level_approval');
        $definition = $this->patchAssigneeUserId($definition, 'director_approval', (int) $users['director']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'EDGE-RUN-001']);

        DBFlow::start('sequential_multi_level_approval', $subject, $users['first']->getKey());

        $this->expectException(WorkflowAlreadyRunningException::class);

        DBFlow::start('sequential_multi_level_approval', $subject, $users['first']->getKey());
    }

    #[Test]
    public function approving_a_completed_task_throws_not_pending(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition = $this->patchAssigneeUserId($definition, 'high_value_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'EDGE-APPROVE-001'])
            ->withWorkflowVariables(['amount' => 15000]);

        $instance = DBFlow::start('conditional_routing', $subject, $users['first']->getKey());

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('status', WorkflowTaskStatus::Pending)
            ->firstOrFail();

        DBFlow::approve($task, $users['first']->getKey());

        $this->expectException(TaskNotPendingException::class);

        DBFlow::approve($task->fresh(), $users['first']->getKey());
    }

    #[Test]
    public function unauthorized_actor_cannot_approve_pending_task(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition = $this->patchAssigneeUserId($definition, 'high_value_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'EDGE-AUTH-001'])
            ->withWorkflowVariables(['amount' => 15000]);

        $instance = DBFlow::start('conditional_routing', $subject, $users['first']->getKey());

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('status', WorkflowTaskStatus::Pending)
            ->firstOrFail();

        $this->expectException(UserCannotApproveTaskException::class);

        DBFlow::approve($task, $users['second']->getKey());
    }

    #[Test]
    public function approving_with_a_null_actor_throws_unauthorized(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition = $this->patchAssigneeUserId($definition, 'high_value_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'EDGE-NULL-ACTOR-001'])
            ->withWorkflowVariables(['amount' => 15000]);

        $instance = DBFlow::start('conditional_routing', $subject, $users['first']->getKey());

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('status', WorkflowTaskStatus::Pending)
            ->firstOrFail();

        $this->expectException(UserCannotApproveTaskException::class);

        DBFlow::approve($task);
    }

    #[Test]
    public function approving_a_task_on_a_non_running_instance_throws_not_pending(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition = $this->patchAssigneeUserId($definition, 'high_value_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'EDGE-INSTANCE-STATUS-001'])
            ->withWorkflowVariables(['amount' => 15000]);

        $instance = DBFlow::start('conditional_routing', $subject, $users['first']->getKey());

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('status', WorkflowTaskStatus::Pending)
            ->firstOrFail();

        // Simulate an instance forced into a terminal state through an out-of-band update while
        // its task row is still (inconsistently) pending; approve/reject must not trust the task
        // status alone.
        $instance->forceFill(['status' => WorkflowInstanceStatus::Cancelled])->save();

        $this->expectException(TaskNotPendingException::class);

        DBFlow::approve($task, $users['first']->getKey());
    }

    #[Test]
    public function rejecting_a_task_on_a_non_running_instance_throws_not_pending(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition = $this->patchAssigneeUserId($definition, 'high_value_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'EDGE-INSTANCE-STATUS-002'])
            ->withWorkflowVariables(['amount' => 15000]);

        $instance = DBFlow::start('conditional_routing', $subject, $users['first']->getKey());

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('status', WorkflowTaskStatus::Pending)
            ->firstOrFail();

        $instance->forceFill(['status' => WorkflowInstanceStatus::Cancelled])->save();

        $this->expectException(TaskNotPendingException::class);

        DBFlow::reject($task, $users['first']->getKey());
    }

    #[Test]
    public function reject_end_strategy_terminates_instance(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition = $this->patchAssigneeUserId($definition, 'high_value_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'EDGE-REJECT-001'])
            ->withWorkflowVariables(['amount' => 15000]);

        $instance = DBFlow::start('conditional_routing', $subject, $users['first']->getKey());

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('status', WorkflowTaskStatus::Pending)
            ->firstOrFail();

        $rejected = DBFlow::reject($task, $users['first']->getKey(), 'Not approved', RejectStrategy::End);

        $this->assertSame(WorkflowInstanceStatus::Rejected, $rejected->status);
        $this->assertNotNull($rejected->completed_at);
        $this->assertNull($rejected->active_key);
    }

    #[Test]
    public function cyclic_fixture_fails_topology_validation(): void
    {
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition['transitions'][] = [
            'from' => 'high_value_review',
            'to' => 'amount_gate',
        ];

        $result = (new BlueprintValidator)->validateArray($definition);

        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasErrorCode($result->errors(), 'cycle_detected'));
    }

    #[Test]
    public function validate_or_fail_throws_topology_exception_for_cycle(): void
    {
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition['transitions'][] = [
            'from' => 'high_value_review',
            'to' => 'amount_gate',
        ];

        $this->expectException(InvalidWorkflowTopologyException::class);

        (new BlueprintValidator)->validateOrFail($definition);
    }

    /**
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function hasErrorCode(array $errors, string $code): bool
    {
        foreach ($errors as $error) {
            if (($error['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
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
