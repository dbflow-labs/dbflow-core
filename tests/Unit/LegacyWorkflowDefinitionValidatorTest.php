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
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Services\WorkflowDefinitionValidator as LegacyWorkflowDefinitionValidator;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class LegacyWorkflowDefinitionValidatorTest extends TestCase
{
    #[Test]
    public function legacy_validator_rejects_topology_defective_definitions_by_delegation(): void
    {
        $validator = new LegacyWorkflowDefinitionValidator;

        $this->expectException(InvalidWorkflowDefinitionException::class);

        $validator->validate($this->topologyDefectiveDefinition());
    }

    #[Test]
    public function legacy_validator_accepts_valid_definitions_by_delegation(): void
    {
        $validator = new LegacyWorkflowDefinitionValidator;

        $validator->validate($this->minimalValidDefinition());

        $this->assertTrue(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalValidDefinition(): array
    {
        return [
            WorkflowDefinitionSchema::FIELD_KEY => 'legacy_valid_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'Legacy Valid Flow',
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
                        WorkflowDefinitionSchema::CONFIG_APPROVAL_MODE => WorkflowDefinitionSchema::APPROVAL_MODE_ANY,
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

    /**
     * @return array<string, mixed>
     */
    private function topologyDefectiveDefinition(): array
    {
        $definition = $this->minimalValidDefinition();
        $definition[WorkflowDefinitionSchema::FIELD_KEY] = 'legacy_defective_flow';
        $definition[WorkflowDefinitionSchema::FIELD_NODES][] = [
            WorkflowDefinitionSchema::FIELD_KEY => 'floating',
            WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_ACTION,
            WorkflowDefinitionSchema::FIELD_NAME => 'Floating',
            WorkflowDefinitionSchema::FIELD_CONFIG => ['action_key' => 'noop'],
        ];

        return $definition;
    }
}
