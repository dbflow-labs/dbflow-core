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
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Tests\Concerns\LoadsBlueprintFixtures;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConditionRoutingFlowTest extends TestCase
{
    use LoadsBlueprintFixtures;
    use RegistersEngineTestResources;

    #[Test]
    public function low_amount_routes_to_fast_track_end_without_approval_task(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition = $this->patchAssigneeUserId($definition, 'high_value_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'COND-LOW-001'])
            ->withWorkflowVariables(['amount' => 2500]);

        $instance = DBFlow::start('conditional_routing', $subject, $users['first']->getKey());

        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end_fast_track', $instance->current_node_key);
        $this->assertSame(
            0,
            WorkflowTask::query()->where('workflow_instance_id', $instance->getKey())->count(),
        );
        $this->assertTrue(
            WorkflowLog::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('event', WorkflowLogEvent::WorkflowCompleted)
                ->exists(),
        );
    }

    #[Test]
    public function high_amount_routes_to_review_task_before_completion(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadBlueprintFixture('conditional_routing');
        $definition = $this->patchAssigneeUserId($definition, 'high_value_review', (int) $users['first']->getKey());
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()
            ->create(['reference_code' => 'COND-HIGH-001'])
            ->withWorkflowVariables(['amount' => 15000]);

        $instance = DBFlow::start('conditional_routing', $subject, $users['first']->getKey());

        $this->assertSame(WorkflowInstanceStatus::Running, $instance->status);
        $this->assertSame('high_value_review', $instance->current_node_key);

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('node_key', 'high_value_review')
            ->where('status', WorkflowTaskStatus::Pending)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $task);

        DBFlow::approve($task, $users['first']->getKey());

        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end_standard', $instance->current_node_key);
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
