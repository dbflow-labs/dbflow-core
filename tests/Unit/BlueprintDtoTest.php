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

namespace DbflowLabs\Core\Tests\Unit;

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\Nodes\ConditionNode;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\NodeType;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use PHPUnit\Framework\Attributes\Test;

final class BlueprintDtoTest extends TestCase
{
    #[Test]
    public function blueprint_round_trips_full_topology_from_array(): void
    {
        $definition = [
            WorkflowDefinitionSchema::FIELD_KEY => 'expense_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'Expense Flow',
            WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION => '1.0',
            WorkflowDefinitionSchema::FIELD_DESCRIPTION => 'Sample topology',
            WorkflowDefinitionSchema::FIELD_METADATA => ['canvas' => 'pro'],
            WorkflowDefinitionSchema::FIELD_NODES => [
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'start',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_START,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Start',
                    WorkflowDefinitionSchema::FIELD_POSITION => ['x' => 10, 'y' => 20],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'review',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_APPROVAL,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Review',
                    WorkflowDefinitionSchema::FIELD_CONFIG => [
                        WorkflowDefinitionSchema::CONFIG_APPROVAL_MODE => ApprovalMode::Any->value,
                        WorkflowDefinitionSchema::CONFIG_ASSIGNEES => [
                            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
                            WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => '1',
                        ],
                    ],
                    WorkflowDefinitionSchema::FIELD_METADATA => ['shape' => 'rect'],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'amount_check',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_CONDITION,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Amount Check',
                    WorkflowDefinitionSchema::FIELD_CONFIG => [
                        WorkflowDefinitionSchema::CONFIG_EXPRESSION => 'amount > 1000',
                    ],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'notify',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_ACTION,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Notify Finance',
                    WorkflowDefinitionSchema::FIELD_CONFIG => [
                        'action' => 'notify_finance',
                        'payload' => ['channel' => 'mail'],
                    ],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'end',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_END,
                    WorkflowDefinitionSchema::FIELD_NAME => 'End',
                    WorkflowDefinitionSchema::FIELD_CONFIG => [
                        WorkflowDefinitionSchema::CONFIG_STATUS => 'completed',
                    ],
                ],
            ],
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'start',
                    WorkflowDefinitionSchema::FIELD_TO => 'review',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'review',
                    WorkflowDefinitionSchema::FIELD_TO => 'amount_check',
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'amount_check',
                    WorkflowDefinitionSchema::FIELD_TO => 'notify',
                    WorkflowDefinitionSchema::FIELD_CONDITION => 'amount > 1000',
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'notify',
                    WorkflowDefinitionSchema::FIELD_TO => 'end',
                ],
            ],
        ];

        $blueprint = Blueprint::fromArray($definition);
        $serialized = $blueprint->toArray();

        $this->assertSame('expense_flow', $blueprint->key());
        $this->assertCount(5, $blueprint->nodes());

        $approval = $blueprint->findNode('review');
        $this->assertInstanceOf(ApprovalNode::class, $approval);
        $this->assertSame(ApprovalMode::Any, $approval->approvalMode());
        $this->assertSame(['shape' => 'rect'], $approval->metadata());

        $condition = $blueprint->findNode('amount_check');
        $this->assertInstanceOf(ConditionNode::class, $condition);
        $this->assertSame('amount > 1000', $condition->expression());

        $action = $blueprint->findNode('notify');
        $this->assertInstanceOf(ActionNode::class, $action);
        $this->assertSame('notify_finance', $action->actionKey());

        $end = $blueprint->findNode('end');
        $this->assertInstanceOf(EndNode::class, $end);
        $this->assertSame('completed', $end->status());

        $this->assertSame('action_key', array_key_first($serialized['nodes'][3]['config']));
        $this->assertSame('notify_finance', $serialized['nodes'][3]['config']['action_key']);

        $validator = new WorkflowDefinitionValidator;
        $result = $validator->validate($serialized);

        $this->assertTrue($result->isValid(), json_encode($result->errors()));
    }

    #[Test]
    public function node_metadata_can_be_mutated_without_affecting_execution_fields(): void
    {
        $node = new ActionNode(
            key: 'sync',
            name: 'Sync',
            actionKey: 'sync_record',
            metadata: ['x' => 1],
        );

        $node->setMetadata(['x' => 200, 'lines' => ['a-b']]);

        $array = $node->toArray();

        $this->assertSame(NodeType::Action, $node->type());
        $this->assertSame('sync_record', $array['config']['action_key']);
        $this->assertSame(['x' => 200, 'lines' => ['a-b']], $array['metadata']);
    }
}
