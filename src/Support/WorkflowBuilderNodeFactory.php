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
use InvalidArgumentException;

final class WorkflowBuilderNodeFactory
{
    /**
     * @param  array{x?: mixed, y?: mixed}  $position
     * @return array<string, mixed>
     */
    public function make(
        string $type,
        string $key,
        string $name,
        array $position = [],
    ): array {
        if ($key === '') {
            throw new InvalidArgumentException('Node key is required.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Node name is required.');
        }

        if (! in_array($type, WorkflowDefinitionSchema::nodeTypes(), true)) {
            throw new InvalidArgumentException("Unsupported node type [{$type}].");
        }

        $node = [
            'key' => $key,
            'type' => $type,
            'name' => $name,
            'position' => [
                'x' => $this->sanitizeCoordinate($position['x'] ?? 100),
                'y' => $this->sanitizeCoordinate($position['y'] ?? 120),
            ],
        ];

        $config = $this->defaultConfigForType($type);

        if ($config !== []) {
            $node['config'] = $config;
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultConfigForType(string $type): array
    {
        return match ($type) {
            WorkflowDefinitionSchema::NODE_TYPE_START => [],
            WorkflowDefinitionSchema::NODE_TYPE_APPROVAL => [
                WorkflowDefinitionSchema::CONFIG_APPROVAL_MODE => WorkflowDefinitionSchema::APPROVAL_MODE_ANY,
                WorkflowDefinitionSchema::CONFIG_ASSIGNEES => [
                    WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION,
                    WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => '',
                ],
            ],
            WorkflowDefinitionSchema::NODE_TYPE_CONDITION => [
                WorkflowDefinitionSchema::CONFIG_EXPRESSION => '',
            ],
            WorkflowDefinitionSchema::NODE_TYPE_ACTION => [
                'action' => '',
            ],
            WorkflowDefinitionSchema::NODE_TYPE_END => [
                WorkflowDefinitionSchema::CONFIG_STATUS => 'completed',
            ],
            default => [],
        };
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
