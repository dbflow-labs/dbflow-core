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

namespace DbflowLabs\Core\Definitions\Nodes;

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\NodeType;
use InvalidArgumentException;

final class ApprovalNode extends AbstractWorkflowNode
{
    /**
     * @param  array{type: string, value?: string, callback?: string}  $assignees
     */
    public function __construct(
        string $key,
        string $name,
        private readonly ApprovalMode $approvalMode,
        private readonly array $assignees,
        ?array $position = null,
        array $metadata = [],
    ) {
        parent::__construct($key, $name, $position, $metadata);
    }

    public function type(): NodeType
    {
        return NodeType::Approval;
    }

    public function approvalMode(): ApprovalMode
    {
        return $this->approvalMode;
    }

    /**
     * @return array{type: string, value?: string, callback?: string}
     */
    public function assignees(): array
    {
        return $this->assignees;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $key = self::requireString($data, WorkflowDefinitionSchema::FIELD_KEY);
        $name = self::requireString($data, WorkflowDefinitionSchema::FIELD_NAME);
        $config = is_array($data[WorkflowDefinitionSchema::FIELD_CONFIG] ?? null)
            ? $data[WorkflowDefinitionSchema::FIELD_CONFIG]
            : [];

        $approvalModeValue = $config[WorkflowDefinitionSchema::CONFIG_APPROVAL_MODE] ?? ApprovalMode::Any->value;
        $approvalMode = ApprovalMode::tryFrom((string) $approvalModeValue) ?? ApprovalMode::Any;

        $assignees = is_array($config[WorkflowDefinitionSchema::CONFIG_ASSIGNEES] ?? null)
            ? $config[WorkflowDefinitionSchema::CONFIG_ASSIGNEES]
            : [];

        $assigneeType = is_string($assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE] ?? null)
            ? $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE]
            : WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION;

        $normalizedAssignees = [
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => $assigneeType,
        ];

        if (array_key_exists(WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE, $assignees)) {
            $value = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE];
            $normalizedAssignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE] = is_string($value)
                ? $value
                : (string) $value;
        }

        if (isset($assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK])
            && is_string($assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK])
            && $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK] !== '') {
            $normalizedAssignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK] =
                $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK];
        }

        return new self(
            key: $key,
            name: $name,
            approvalMode: $approvalMode,
            assignees: $normalizedAssignees,
            position: self::hydratePosition($data),
            metadata: self::hydrateMetadata($data),
        );
    }

    public function toArray(): array
    {
        $payload = parent::toArray();
        $payload[WorkflowDefinitionSchema::FIELD_CONFIG] = [
            WorkflowDefinitionSchema::CONFIG_APPROVAL_MODE => $this->approvalMode->value,
            WorkflowDefinitionSchema::CONFIG_ASSIGNEES => $this->assignees,
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requireString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Approval node field [{$field}] is required.");
        }

        return $value;
    }
}
