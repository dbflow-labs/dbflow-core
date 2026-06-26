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

namespace DbflowLabs\Core\Support;

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;

final class WorkflowBuilderNodeConfigNormalizer
{
    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function normalize(array $node): array
    {
        $key = is_string($node[WorkflowDefinitionSchema::FIELD_KEY] ?? null)
            ? $node[WorkflowDefinitionSchema::FIELD_KEY]
            : '';
        $type = is_string($node[WorkflowDefinitionSchema::FIELD_TYPE] ?? null)
            ? $node[WorkflowDefinitionSchema::FIELD_TYPE]
            : '';

        if ($key === '' || $type === '') {
            return [];
        }

        $normalized = [
            WorkflowDefinitionSchema::FIELD_KEY => $key,
            WorkflowDefinitionSchema::FIELD_TYPE => $type,
            WorkflowDefinitionSchema::FIELD_NAME => is_string($node[WorkflowDefinitionSchema::FIELD_NAME] ?? null)
                ? $node[WorkflowDefinitionSchema::FIELD_NAME]
                : (string) ($node[WorkflowDefinitionSchema::FIELD_NAME] ?? ''),
        ];

        $config = $this->normalizeConfig(
            $type,
            is_array($node[WorkflowDefinitionSchema::FIELD_CONFIG] ?? null)
                ? $node[WorkflowDefinitionSchema::FIELD_CONFIG]
                : [],
        );

        if ($config !== []) {
            $normalized[WorkflowDefinitionSchema::FIELD_CONFIG] = $config;
        }

        if (isset($node[WorkflowDefinitionSchema::FIELD_METADATA]) && is_array($node[WorkflowDefinitionSchema::FIELD_METADATA])) {
            $normalized[WorkflowDefinitionSchema::FIELD_METADATA] = $node[WorkflowDefinitionSchema::FIELD_METADATA];
        }

        if (isset($node[WorkflowDefinitionSchema::FIELD_POSITION]) && is_array($node[WorkflowDefinitionSchema::FIELD_POSITION])) {
            $position = $node[WorkflowDefinitionSchema::FIELD_POSITION];
            $normalized[WorkflowDefinitionSchema::FIELD_POSITION] = [
                'x' => $this->sanitizeCoordinate($position['x'] ?? 0),
                'y' => $this->sanitizeCoordinate($position['y'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(string $type, array $config): array
    {
        return match ($type) {
            WorkflowDefinitionSchema::NODE_TYPE_START => [],
            WorkflowDefinitionSchema::NODE_TYPE_APPROVAL => $this->normalizeApprovalConfig($config),
            WorkflowDefinitionSchema::NODE_TYPE_CONDITION => $this->normalizeConditionConfig($config),
            WorkflowDefinitionSchema::NODE_TYPE_ACTION => $this->normalizeActionConfig($config),
            WorkflowDefinitionSchema::NODE_TYPE_END => $this->normalizeEndConfig($config),
            default => $config,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeApprovalConfig(array $config): array
    {
        $approvalMode = $config[WorkflowDefinitionSchema::CONFIG_APPROVAL_MODE] ?? WorkflowDefinitionSchema::APPROVAL_MODE_ANY;

        if (! is_string($approvalMode) || ! in_array($approvalMode, WorkflowDefinitionSchema::approvalModes(), true)) {
            $approvalMode = WorkflowDefinitionSchema::APPROVAL_MODE_ANY;
        }

        $assignees = is_array($config[WorkflowDefinitionSchema::CONFIG_ASSIGNEES] ?? null)
            ? $config[WorkflowDefinitionSchema::CONFIG_ASSIGNEES]
            : [];

        $assigneeType = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE] ?? WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION;

        if (! is_string($assigneeType) || ! in_array($assigneeType, WorkflowDefinitionSchema::assigneeTypes(), true)) {
            $assigneeType = WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION;
        }

        $assigneeValue = is_string($assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE] ?? null)
            ? $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE]
            : (string) ($assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE] ?? '');

        $normalizedAssignees = [
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => $assigneeType,
            WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => $assigneeValue,
        ];

        if ($assigneeType === WorkflowDefinitionSchema::ASSIGNEE_TYPE_CALLBACK) {
            $callback = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK] ?? null;

            if (is_string($callback) && $callback !== '') {
                $normalizedAssignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK] = $callback;
            }
        }

        return [
            WorkflowDefinitionSchema::CONFIG_APPROVAL_MODE => $approvalMode,
            WorkflowDefinitionSchema::CONFIG_ASSIGNEES => $normalizedAssignees,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeConditionConfig(array $config): array
    {
        $expression = $config[WorkflowDefinitionSchema::CONFIG_EXPRESSION] ?? '';

        return [
            WorkflowDefinitionSchema::CONFIG_EXPRESSION => is_string($expression)
                ? $expression
                : (string) $expression,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeActionConfig(array $config): array
    {
        $action = $config['action'] ?? '';

        $normalized = [
            'action' => is_string($action) ? $action : (string) $action,
        ];

        if (isset($config['payload']) && is_array($config['payload'])) {
            $normalized['payload'] = $config['payload'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeEndConfig(array $config): array
    {
        $status = $config[WorkflowDefinitionSchema::CONFIG_STATUS] ?? 'completed';

        return [
            WorkflowDefinitionSchema::CONFIG_STATUS => is_string($status) && $status !== ''
                ? $status
                : 'completed',
        ];
    }

    private function sanitizeCoordinate(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, min(10000, $value));
        }

        if (is_float($value)) {
            return max(0, min(10000, (int) round($value)));
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, min(10000, (int) round((float) $value)));
        }

        return 0;
    }
}
