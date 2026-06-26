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

namespace DbflowLabs\Core\Templates;

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;

final class WorkflowTemplateRegistry
{
    public const TEMPLATE_SIMPLE_APPROVAL = 'simple_approval';

    public const TEMPLATE_APPROVAL_WITH_REJECTION = 'approval_with_rejection';

    public const TEMPLATE_CONDITIONAL_APPROVAL = 'conditional_approval';

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     category: string,
     *     definition: array<string, mixed>
     * }>
     */
    public function all(): array
    {
        return [
            $this->simpleApprovalTemplate(),
            $this->approvalWithRejectionTemplate(),
            $this->conditionalApprovalTemplate(),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     category: string,
     *     definition: array<string, mixed>
     * }|null
     */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     category: string,
     *     definition: array<string, mixed>
     * }
     */
    private function simpleApprovalTemplate(): array
    {
        return [
            'key' => self::TEMPLATE_SIMPLE_APPROVAL,
            'name' => (string) __('dbflow.pages.workflows.templates.items.simple_approval.name'),
            'description' => (string) __('dbflow.pages.workflows.templates.items.simple_approval.description'),
            'category' => 'approval',
            'definition' => [
                WorkflowDefinitionSchema::FIELD_KEY => '',
                WorkflowDefinitionSchema::FIELD_NAME => '',
                WorkflowDefinitionSchema::FIELD_NODES => [
                    $this->startNode(100, 120),
                    $this->approvalNode('approval', 360, 120),
                    $this->endNode('approved', 'approved', 620, 120),
                ],
                WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                    $this->transition('start', 'approval'),
                    $this->transition('approval', 'approved'),
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     category: string,
     *     definition: array<string, mixed>
     * }
     */
    private function approvalWithRejectionTemplate(): array
    {
        return [
            'key' => self::TEMPLATE_APPROVAL_WITH_REJECTION,
            'name' => (string) __('dbflow.pages.workflows.templates.items.approval_with_rejection.name'),
            'description' => (string) __('dbflow.pages.workflows.templates.items.approval_with_rejection.description'),
            'category' => 'approval',
            'definition' => [
                WorkflowDefinitionSchema::FIELD_KEY => '',
                WorkflowDefinitionSchema::FIELD_NAME => '',
                WorkflowDefinitionSchema::FIELD_NODES => [
                    $this->startNode(100, 120),
                    $this->approvalNode('approval', 360, 120),
                    $this->endNode('approved', 'approved', 620, 80),
                    $this->endNode('rejected', 'rejected', 620, 200),
                ],
                WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                    $this->transition('start', 'approval'),
                    $this->transition('approval', 'approved'),
                    $this->transition('approval', 'rejected'),
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     category: string,
     *     definition: array<string, mixed>
     * }
     */
    private function conditionalApprovalTemplate(): array
    {
        return [
            'key' => self::TEMPLATE_CONDITIONAL_APPROVAL,
            'name' => (string) __('dbflow.pages.workflows.templates.items.conditional_approval.name'),
            'description' => (string) __('dbflow.pages.workflows.templates.items.conditional_approval.description'),
            'category' => 'approval',
            'definition' => [
                WorkflowDefinitionSchema::FIELD_KEY => '',
                WorkflowDefinitionSchema::FIELD_NAME => '',
                WorkflowDefinitionSchema::FIELD_NODES => [
                    $this->startNode(100, 120),
                    $this->conditionNode('amount_condition', 'model.total_amount > 5000', 360, 120),
                    $this->approvalNode('approval', 620, 120),
                    $this->endNode('approved', 'approved', 880, 120),
                ],
                WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                    $this->transition('start', 'amount_condition'),
                    [
                        WorkflowDefinitionSchema::FIELD_FROM => 'amount_condition',
                        WorkflowDefinitionSchema::FIELD_TO => 'approval',
                        WorkflowDefinitionSchema::FIELD_CONDITION => 'model.total_amount > 5000',
                    ],
                    [
                        WorkflowDefinitionSchema::FIELD_FROM => 'amount_condition',
                        WorkflowDefinitionSchema::FIELD_TO => 'approved',
                        WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                    ],
                    $this->transition('approval', 'approved'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function startNode(int $x, int $y): array
    {
        return [
            WorkflowDefinitionSchema::FIELD_KEY => 'start',
            WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_START,
            WorkflowDefinitionSchema::FIELD_NAME => (string) __('dbflow.pages.workflows.structure_form.defaults.start_name'),
            WorkflowDefinitionSchema::FIELD_POSITION => ['x' => $x, 'y' => $y],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalNode(string $key, int $x, int $y): array
    {
        return [
            WorkflowDefinitionSchema::FIELD_KEY => $key,
            WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_APPROVAL,
            WorkflowDefinitionSchema::FIELD_NAME => (string) __('dbflow.pages.workflows.structure_form.defaults.approval_name'),
            WorkflowDefinitionSchema::FIELD_CONFIG => [
                WorkflowDefinitionSchema::CONFIG_APPROVAL_MODE => WorkflowDefinitionSchema::APPROVAL_MODE_ANY,
                WorkflowDefinitionSchema::CONFIG_ASSIGNEES => [
                    WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION,
                    WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => '',
                ],
            ],
            WorkflowDefinitionSchema::FIELD_POSITION => ['x' => $x, 'y' => $y],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionNode(string $key, string $expression, int $x, int $y): array
    {
        return [
            WorkflowDefinitionSchema::FIELD_KEY => $key,
            WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_CONDITION,
            WorkflowDefinitionSchema::FIELD_NAME => (string) __('dbflow.pages.workflows.structure_form.defaults.amount_condition_name'),
            WorkflowDefinitionSchema::FIELD_CONFIG => [
                WorkflowDefinitionSchema::CONFIG_EXPRESSION => $expression,
            ],
            WorkflowDefinitionSchema::FIELD_POSITION => ['x' => $x, 'y' => $y],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function endNode(string $key, string $status, int $x, int $y): array
    {
        $nameKey = match ($status) {
            'rejected' => 'dbflow.pages.workflows.structure_form.defaults.rejected_name',
            default => 'dbflow.pages.workflows.structure_form.defaults.approved_name',
        };

        return [
            WorkflowDefinitionSchema::FIELD_KEY => $key,
            WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_END,
            WorkflowDefinitionSchema::FIELD_NAME => (string) __($nameKey),
            WorkflowDefinitionSchema::FIELD_CONFIG => [
                WorkflowDefinitionSchema::CONFIG_STATUS => $status,
            ],
            WorkflowDefinitionSchema::FIELD_POSITION => ['x' => $x, 'y' => $y],
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function transition(string $from, string $to): array
    {
        return [
            WorkflowDefinitionSchema::FIELD_FROM => $from,
            WorkflowDefinitionSchema::FIELD_TO => $to,
        ];
    }
}
