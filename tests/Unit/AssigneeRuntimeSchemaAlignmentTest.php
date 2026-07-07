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
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\BlueprintValidator;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use PHPUnit\Framework\Attributes\Test;

final class AssigneeRuntimeSchemaAlignmentTest extends TestCase
{
    #[Test]
    public function direct_user_assignee_type_validates(): void
    {
        $result = (new WorkflowDefinitionValidator)->validate($this->definitionWithAssignee(
            WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            '1',
        ));

        $this->assertTrue($result->isValid(), json_encode($result->errors()));
    }

    #[Test]
    public function role_assignee_fails_validation_with_clear_message(): void
    {
        $result = (new WorkflowDefinitionValidator)->validate($this->definitionWithAssignee(
            WorkflowDefinitionSchema::ASSIGNEE_TYPE_ROLE,
            'manager',
        ));

        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasErrorCode($result->errors(), 'unsupported_assignee_type'));
        $this->assertStringContainsString('role', strtolower($result->errors()[0]['message'] ?? ''));
    }

    #[Test]
    public function permission_assignee_requires_registered_resolver_when_registry_is_provided(): void
    {
        $registry = new AssigneeResolverRegistry;
        $validator = WorkflowDefinitionValidator::withAssigneeResolverRegistry($registry);

        $result = $validator->validate($this->definitionWithAssignee(
            WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION,
            'finance.approve',
        ));

        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasErrorCode($result->errors(), 'missing_assignee_resolver'));
        $this->assertStringContainsString('finance.approve', $result->errors()[0]['message'] ?? '');
    }

    #[Test]
    public function user_assignee_with_numeric_value_validates(): void
    {
        $result = (new WorkflowDefinitionValidator)->validate($this->definitionWithAssignee(
            WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            '42',
        ));

        $this->assertTrue($result->isValid(), json_encode($result->errors()));
    }

    #[Test]
    public function user_assignee_with_uuid_value_validates(): void
    {
        $result = (new WorkflowDefinitionValidator)->validate($this->definitionWithAssignee(
            WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            '550e8400-e29b-41d4-a716-446655440000',
        ));

        $this->assertTrue($result->isValid(), json_encode($result->errors()));
    }

    #[Test]
    public function user_assignee_with_value_exceeding_column_width_fails_validation(): void
    {
        $result = (new WorkflowDefinitionValidator)->validate($this->definitionWithAssignee(
            WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            str_repeat('a', 65),
        ));

        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasErrorCode($result->errors(), 'invalid_value'));
    }

    #[Test]
    public function user_assignee_with_missing_value_fails_validation(): void
    {
        $definition = $this->definitionWithAssignee(
            WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
            '1',
        );
        $definition['nodes'][1]['config']['assignees']['value'] = '';

        $result = (new WorkflowDefinitionValidator)->validate($definition);

        $this->assertFalse($result->isValid());
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
    private function definitionWithAssignee(string $assigneeType, string $assigneeValue): array
    {
        return [
            WorkflowDefinitionSchema::FIELD_KEY => 'assignee_alignment_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'Assignee Alignment Flow',
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
                            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => $assigneeType,
                            WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => $assigneeValue,
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
