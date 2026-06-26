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

final class WorkflowNodeVisualRegistry
{
    /**
     * @return array{type: string, label: string, description: string, icon: string, badge: string, css_class: string}
     */
    public static function forType(string $type): array
    {
        return match ($type) {
            WorkflowDefinitionSchema::NODE_TYPE_START => [
                'type' => 'start',
                'label' => 'Start',
                'description' => 'Workflow entry point.',
                'icon' => 'heroicon-o-play-circle',
                'badge' => 'Start',
                'css_class' => 'dbflow-node-start',
            ],
            WorkflowDefinitionSchema::NODE_TYPE_APPROVAL => [
                'type' => 'approval',
                'label' => 'Approval',
                'description' => 'Requires one or more assignees to approve.',
                'icon' => 'heroicon-o-check-badge',
                'badge' => 'Approval',
                'css_class' => 'dbflow-node-approval',
            ],
            WorkflowDefinitionSchema::NODE_TYPE_CONDITION => [
                'type' => 'condition',
                'label' => 'Condition',
                'description' => 'Routes the workflow based on a condition.',
                'icon' => 'heroicon-o-question-mark-circle',
                'badge' => 'Condition',
                'css_class' => 'dbflow-node-condition',
            ],
            WorkflowDefinitionSchema::NODE_TYPE_ACTION => [
                'type' => 'action',
                'label' => 'Action',
                'description' => 'Runs an automatic workflow action.',
                'icon' => 'heroicon-o-cog',
                'badge' => 'Action',
                'css_class' => 'dbflow-node-action',
            ],
            WorkflowDefinitionSchema::NODE_TYPE_END => [
                'type' => 'end',
                'label' => 'End',
                'description' => 'Workflow completion point.',
                'icon' => 'heroicon-o-flag',
                'badge' => 'End',
                'css_class' => 'dbflow-node-end',
            ],
            default => [
                'type' => $type,
                'label' => 'Unknown',
                'description' => 'Unknown workflow node type.',
                'icon' => 'heroicon-o-question-mark-circle',
                'badge' => 'Unknown',
                'css_class' => 'dbflow-node-unknown',
            ],
        };
    }

    /**
     * @return list<array{type: string, label: string, description: string, icon: string, badge: string, css_class: string}>
     */
    public static function all(): array
    {
        return array_map(
            fn (string $type): array => self::forType($type),
            WorkflowDefinitionSchema::nodeTypes(),
        );
    }
}
