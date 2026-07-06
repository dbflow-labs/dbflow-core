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

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Support\LogicFlowDefinitionMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogicFlowDefinitionMapperTest extends TestCase
{
    #[Test]
    public function to_logic_flow_maps_supported_node_types_and_transition_metadata(): void
    {
        $mapper = new LogicFlowDefinitionMapper();

        $result = $mapper->toLogicFlow([
            WorkflowDefinitionSchema::FIELD_KEY => 'lf_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'LogicFlow Flow',
            WorkflowDefinitionSchema::FIELD_NODES => [
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'start',
                    WorkflowDefinitionSchema::FIELD_TYPE => 'start',
                    WorkflowDefinitionSchema::FIELD_NAME => 'Start',
                    WorkflowDefinitionSchema::FIELD_POSITION => ['x' => '12.5', 'y' => -3],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'approval',
                    WorkflowDefinitionSchema::FIELD_TYPE => 'approval',
                    WorkflowDefinitionSchema::FIELD_NAME => 'Approval',
                    WorkflowDefinitionSchema::FIELD_CONFIG => ['approval_mode' => 'any'],
                    WorkflowDefinitionSchema::FIELD_METADATA => ['canvas' => ['color' => 'blue']],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'condition',
                    WorkflowDefinitionSchema::FIELD_TYPE => 'condition',
                    WorkflowDefinitionSchema::FIELD_NAME => 'Condition',
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'action',
                    WorkflowDefinitionSchema::FIELD_TYPE => 'action',
                    WorkflowDefinitionSchema::FIELD_NAME => 'Action',
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'end',
                    WorkflowDefinitionSchema::FIELD_TYPE => 'end',
                    WorkflowDefinitionSchema::FIELD_NAME => 'End',
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => '',
                    WorkflowDefinitionSchema::FIELD_TYPE => 'start',
                    WorkflowDefinitionSchema::FIELD_NAME => 'Skip Me',
                ],
                'invalid-node',
            ],
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'start',
                    WorkflowDefinitionSchema::FIELD_TO => 'approval',
                    WorkflowDefinitionSchema::FIELD_CONDITION => 'model.amount > 0',
                    WorkflowDefinitionSchema::FIELD_PRIORITY => 3,
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'approval',
                    WorkflowDefinitionSchema::FIELD_TO => 'end',
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => '',
                    WorkflowDefinitionSchema::FIELD_TO => 'end',
                ],
                'invalid-transition',
            ],
        ]);

        $this->assertCount(5, $result['nodes']);
        $this->assertSame('dbflow-start', $result['nodes'][0]['type']);
        $this->assertSame(13, $result['nodes'][0]['x']);
        $this->assertSame(0, $result['nodes'][0]['y']);
        $this->assertSame('any', $result['nodes'][1]['properties']['config']['approval_mode']);
        $this->assertSame('blue', $result['nodes'][1]['properties']['metadata']['canvas']['color']);
        $this->assertSame('dbflow-unknown', $mapper->toLogicFlow([
            WorkflowDefinitionSchema::FIELD_NODES => [[
                WorkflowDefinitionSchema::FIELD_KEY => 'mystery',
                WorkflowDefinitionSchema::FIELD_TYPE => 'mystery',
                WorkflowDefinitionSchema::FIELD_NAME => 'Mystery',
            ]],
        ])['nodes'][0]['type']);

        $this->assertCount(2, $result['edges']);
        $this->assertSame('start--approval', $result['edges'][0]['id']);
        $this->assertSame('model.amount > 0', $result['edges'][0]['properties']['condition']);
        $this->assertSame(3, $result['edges'][0]['properties']['priority']);
        $this->assertTrue($result['edges'][0]['properties']['is_default']);
    }

    #[Test]
    public function from_logic_flow_round_trips_definition_shape(): void
    {
        $mapper = new LogicFlowDefinitionMapper();

        $baseDefinition = [
            WorkflowDefinitionSchema::FIELD_KEY => 'round_trip_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'Round Trip Flow',
            WorkflowDefinitionSchema::FIELD_DESCRIPTION => 'Round trip description',
            WorkflowDefinitionSchema::FIELD_VERSION => 2,
            WorkflowDefinitionSchema::FIELD_METADATA => ['source' => 'unit-test'],
            WorkflowDefinitionSchema::FIELD_NODES => [
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'start',
                    WorkflowDefinitionSchema::FIELD_TYPE => 'start',
                    WorkflowDefinitionSchema::FIELD_NAME => 'Start',
                    WorkflowDefinitionSchema::FIELD_POSITION => ['x' => 10, 'y' => 20],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'end',
                    WorkflowDefinitionSchema::FIELD_TYPE => 'end',
                    WorkflowDefinitionSchema::FIELD_NAME => 'End',
                ],
            ],
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'start',
                    WorkflowDefinitionSchema::FIELD_TO => 'end',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
            ],
        ];

        $graph = $mapper->toLogicFlow($baseDefinition);
        $roundTrip = $mapper->fromLogicFlow($baseDefinition, $graph);

        $this->assertSame('round_trip_flow', $roundTrip[WorkflowDefinitionSchema::FIELD_KEY]);
        $this->assertSame('Round Trip Flow', $roundTrip[WorkflowDefinitionSchema::FIELD_NAME]);
        $this->assertSame('Round trip description', $roundTrip[WorkflowDefinitionSchema::FIELD_DESCRIPTION]);
        $this->assertSame(2, $roundTrip[WorkflowDefinitionSchema::FIELD_VERSION]);
        $this->assertSame('unit-test', $roundTrip[WorkflowDefinitionSchema::FIELD_METADATA]['source']);
        $this->assertCount(2, $roundTrip[WorkflowDefinitionSchema::FIELD_NODES]);
        $this->assertSame('start', $roundTrip[WorkflowDefinitionSchema::FIELD_TRANSITIONS][0][WorkflowDefinitionSchema::FIELD_FROM]);
        $this->assertTrue($roundTrip[WorkflowDefinitionSchema::FIELD_TRANSITIONS][0][WorkflowDefinitionSchema::FIELD_IS_DEFAULT]);
    }

    #[Test]
    public function from_logic_flow_uses_dbflow_type_fallback_for_unknown_logicflow_nodes(): void
    {
        $mapper = new LogicFlowDefinitionMapper();

        $definition = $mapper->fromLogicFlow(
            [
                WorkflowDefinitionSchema::FIELD_KEY => 'fallback_flow',
                WorkflowDefinitionSchema::FIELD_NAME => 'Fallback Flow',
            ],
            [
                'nodes' => [[
                    'id' => 'custom',
                    'type' => 'dbflow-custom',
                    'text' => 'Custom Node',
                    'x' => 1,
                    'y' => 2,
                    'properties' => [
                        'key' => 'custom',
                        'dbflow_type' => 'action',
                        'config' => ['action_key' => 'log'],
                    ],
                ]],
                'edges' => [],
            ],
        );

        $this->assertSame('action', $definition[WorkflowDefinitionSchema::FIELD_NODES][0][WorkflowDefinitionSchema::FIELD_TYPE]);
        $this->assertSame('log', $definition[WorkflowDefinitionSchema::FIELD_NODES][0][WorkflowDefinitionSchema::FIELD_CONFIG]['action_key']);
    }
}
