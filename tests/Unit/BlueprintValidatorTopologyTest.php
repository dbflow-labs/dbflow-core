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
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\BlueprintValidator;
use PHPUnit\Framework\Attributes\Test;

final class BlueprintValidatorTopologyTest extends TestCase
{
    private BlueprintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new BlueprintValidator;
    }

    #[Test]
    public function validator_rejects_isolated_node(): void
    {
        $definition = $this->baseDefinition();
        $definition[WorkflowDefinitionSchema::FIELD_NODES][] = [
            WorkflowDefinitionSchema::FIELD_KEY => 'floating',
            WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_ACTION,
            WorkflowDefinitionSchema::FIELD_NAME => 'Floating',
            WorkflowDefinitionSchema::FIELD_CONFIG => ['action_key' => 'noop'],
        ];

        $result = $this->validator->validateArray($definition);

        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasErrorCode($result->errors(), 'isolated_node'));
    }

    #[Test]
    public function validator_rejects_cycle_as_error(): void
    {
        $definition = [
            WorkflowDefinitionSchema::FIELD_KEY => 'cycle_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'Cycle Flow',
            WorkflowDefinitionSchema::FIELD_NODES => [
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'start',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_START,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Start',
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'a',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_ACTION,
                    WorkflowDefinitionSchema::FIELD_NAME => 'A',
                    WorkflowDefinitionSchema::FIELD_CONFIG => ['action_key' => 'noop'],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'b',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_ACTION,
                    WorkflowDefinitionSchema::FIELD_NAME => 'B',
                    WorkflowDefinitionSchema::FIELD_CONFIG => ['action_key' => 'noop'],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'end',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_END,
                    WorkflowDefinitionSchema::FIELD_NAME => 'End',
                ],
            ],
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                ['from' => 'start', 'to' => 'a'],
                ['from' => 'a', 'to' => 'b'],
                ['from' => 'b', 'to' => 'a'],
                ['from' => 'b', 'to' => 'end'],
            ],
        ];

        $result = $this->validator->validateArray($definition);

        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasErrorCode($result->errors(), 'cycle_detected'));
        $this->assertSame([], $result->warnings());
    }

    #[Test]
    public function validator_rejects_dead_end_branch(): void
    {
        $definition = [
            WorkflowDefinitionSchema::FIELD_KEY => 'dead_end_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'Dead End Flow',
            WorkflowDefinitionSchema::FIELD_NODES => [
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'start',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_START,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Start',
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'check',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_CONDITION,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Check',
                    WorkflowDefinitionSchema::FIELD_CONFIG => [
                        WorkflowDefinitionSchema::CONFIG_EXPRESSION => 'amount > 0',
                    ],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'dead',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_ACTION,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Dead',
                    WorkflowDefinitionSchema::FIELD_CONFIG => ['action_key' => 'noop'],
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'end',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_END,
                    WorkflowDefinitionSchema::FIELD_NAME => 'End',
                ],
            ],
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                ['from' => 'start', 'to' => 'check'],
                [
                    'from' => 'check',
                    'to' => 'dead',
                    'condition' => 'amount > 100',
                ],
                [
                    'from' => 'check',
                    'to' => 'end',
                    'is_default' => true,
                ],
            ],
        ];

        $result = $this->validator->validateArray($definition);

        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasErrorCode($result->errors(), 'dead_end'));
    }

    #[Test]
    public function validator_accepts_minimal_definition_via_blueprint_dto(): void
    {
        $blueprint = Blueprint::fromArray($this->baseDefinition());

        $result = $this->validator->validate($blueprint);

        $this->assertTrue($result->isValid());
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
     * @return array<string, mixed>
     */
    private function baseDefinition(): array
    {
        return [
            WorkflowDefinitionSchema::FIELD_KEY => 'topology_test',
            WorkflowDefinitionSchema::FIELD_NAME => 'Topology Test',
            WorkflowDefinitionSchema::FIELD_NODES => [
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'start',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_START,
                    WorkflowDefinitionSchema::FIELD_NAME => 'Start',
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
                ],
                [
                    WorkflowDefinitionSchema::FIELD_KEY => 'end',
                    WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_END,
                    WorkflowDefinitionSchema::FIELD_NAME => 'End',
                ],
            ],
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'end'],
            ],
        ];
    }
}
