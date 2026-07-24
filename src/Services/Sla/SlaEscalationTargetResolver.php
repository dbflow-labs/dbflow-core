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

namespace DbflowLabs\Core\Services\Sla;

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use InvalidArgumentException;

final class SlaEscalationTargetResolver
{
    public function __construct(
        private readonly AssigneeResolverRegistry $assigneeResolverRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $target
     */
    public function resolveSingleUserId(WorkflowInstance $instance, array $target): string
    {
        $resolver = $target[WorkflowDefinitionSchema::SLA_RESOLVER]
            ?? $target[WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE]
            ?? null;
        $value = $target[WorkflowDefinitionSchema::SLA_VALUE]
            ?? $target[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE]
            ?? null;

        if (! is_string($resolver) || $resolver === '') {
            throw new InvalidArgumentException('SLA escalation target resolver is required.');
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('SLA escalation target value is required.');
        }

        if (! $this->assigneeResolverRegistry->hasResolver($value) && ! in_array($resolver, ['user', 'permission', 'role', 'callback'], true)) {
            throw new InvalidArgumentException("SLA escalation target resolver [{$resolver}] is invalid.");
        }

        if ((bool) config('dbflow.sla.require_registered_escalation_resolver', true)
            && in_array($resolver, ['permission', 'role', 'callback'], true)
            && ! $this->assigneeResolverRegistry->hasResolver($value)) {
            throw new InvalidWorkflowDefinitionException("Escalation assignee resolver [{$value}] is not registered.");
        }

        $assigneeConfig = match ($resolver) {
            'user' => [
                WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER,
                WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => $value,
            ],
            'permission', 'role' => [
                WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION,
                WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE => $value,
            ],
            'callback' => [
                WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE => WorkflowDefinitionSchema::ASSIGNEE_TYPE_CALLBACK,
                WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK => $value,
            ],
            default => throw new InvalidArgumentException("SLA escalation target resolver [{$resolver}] is not supported."),
        };

        $nodeArray = [
            WorkflowDefinitionSchema::FIELD_KEY => 'sla_escalation',
            WorkflowDefinitionSchema::FIELD_NAME => 'SLA Escalation',
            WorkflowDefinitionSchema::FIELD_TYPE => WorkflowDefinitionSchema::NODE_TYPE_APPROVAL,
            WorkflowDefinitionSchema::FIELD_CONFIG => [
                WorkflowDefinitionSchema::CONFIG_ASSIGNEES => $assigneeConfig,
            ],
        ];

        $userIds = $this->assigneeResolverRegistry->resolve(
            $instance,
            \DbflowLabs\Core\Definitions\Nodes\ApprovalNode::fromArray($nodeArray),
        );

        if ($userIds === []) {
            throw new InvalidWorkflowDefinitionException('SLA escalation target resolved zero eligible actors.');
        }

        if (count($userIds) > 1) {
            throw new InvalidWorkflowDefinitionException('SLA escalation target must resolve to exactly one actor.');
        }

        return (string) $userIds[0];
    }
}

