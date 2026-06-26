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
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Support\WorkflowCompletionStatus;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use PHPUnit\Framework\Attributes\Test;

final class EndNodeStatusRuntimeTest extends TestCase
{
    use RegistersEngineTestResources;

    #[Test]
    public function end_node_with_completed_status_completes_as_approved(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->simpleApprovalDefinition('end_completed_flow', 'completed');
        $definition['nodes'][1]['config']['assignees']['value'] = (string) $users['first']->getKey();
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'END-COMPLETE-001']);
        $instance = DBFlow::start('end_completed_flow', $subject, $users['first']->getKey());
        $task = \DbflowLabs\Core\Models\WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->firstOrFail();

        DBFlow::approve($task, $users['first']->getKey());

        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end_completed', $instance->current_node_key);
    }

    #[Test]
    public function end_node_with_rejected_status_completes_as_rejected(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->simpleApprovalDefinition('end_rejected_flow', 'rejected');
        $definition['nodes'][1]['config']['assignees']['value'] = (string) $users['first']->getKey();
        $this->publishDefinition($definition);

        $subject = ContextTestSubject::query()->create(['reference_code' => 'END-REJECT-001']);
        $instance = DBFlow::start('end_rejected_flow', $subject, $users['first']->getKey());
        $task = \DbflowLabs\Core\Models\WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->firstOrFail();

        DBFlow::approve($task, $users['first']->getKey());

        $instance->refresh();
        $this->assertSame(WorkflowInstanceStatus::Rejected, $instance->status);
        $this->assertSame('end_completed', $instance->current_node_key);
    }

    #[Test]
    public function workflow_completion_status_mapper_honors_end_node_status(): void
    {
        $approvedEnd = new EndNode('end', 'End', WorkflowDefinitionSchema::END_NODE_STATUS_COMPLETED);
        $rejectedEnd = new EndNode('end', 'End', WorkflowDefinitionSchema::END_NODE_STATUS_REJECTED);

        $this->assertSame(WorkflowInstanceStatus::Approved, WorkflowCompletionStatus::fromEndNode($approvedEnd));
        $this->assertSame(WorkflowInstanceStatus::Rejected, WorkflowCompletionStatus::fromEndNode($rejectedEnd));
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleApprovalDefinition(string $key, string $endStatus): array
    {
        return [
            'key' => $key,
            'name' => 'End Status Flow',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'review',
                    'type' => 'approval',
                    'name' => 'Review',
                    'config' => [
                        'approval_mode' => 'any',
                        'assignees' => ['type' => 'user', 'value' => '1'],
                    ],
                ],
                [
                    'key' => 'end_completed',
                    'type' => 'end',
                    'name' => 'Completed',
                    'config' => ['status' => $endStatus],
                ],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'end_completed'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function publishDefinition(array $definition): void
    {
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);
    }
}
