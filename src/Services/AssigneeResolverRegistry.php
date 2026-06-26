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

namespace DbflowLabs\Core\Services;

use DbflowLabs\Core\Contracts\AssigneeResolver;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Models\WorkflowInstance;

final class AssigneeResolverRegistry
{
    /**
     * @var array<string, AssigneeResolver>
     */
    private array $resolvers = [];

    public function register(string $key, AssigneeResolver $resolver): void
    {
        $this->resolvers[$key] = $resolver;
    }

    public function hasResolver(string $key): bool
    {
        return isset($this->resolvers[$key]);
    }

    /**
     * @return list<int>
     */
    public function resolve(WorkflowInstance $instance, ApprovalNode $node): array
    {
        $config = $this->extractAssigneeConfig($node->assignees());

        if (isset($config['resolver'])) {
            $resolverKey = (string) $config['resolver'];

            if (! isset($this->resolvers[$resolverKey])) {
                throw new InvalidWorkflowDefinitionException("Unknown assignee resolver [{$resolverKey}].");
            }

            return $this->resolvers[$resolverKey]->resolve($instance, $node->toArray());
        }

        if (($config['type'] ?? null) !== 'user_ids') {
            throw new InvalidWorkflowDefinitionException('Unsupported assignee configuration.');
        }

        $values = $config['value'] ?? [];

        if (! is_array($values)) {
            throw new InvalidWorkflowDefinitionException('Assignee user_ids value must be an array.');
        }

        $userIds = [];
        $seen = [];

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $userId = (int) $value;

            if ($userId <= 0 || isset($seen[$userId])) {
                continue;
            }

            $seen[$userId] = true;
            $userIds[] = $userId;
        }

        if ($userIds === []) {
            throw new InvalidWorkflowDefinitionException('Assignee user_ids must contain at least one valid user id.');
        }

        return $userIds;
    }

    /**
     * @param  array{type?: string, value?: mixed, callback?: string}  $assignees
     * @return array<string, mixed>
     */
    private function extractAssigneeConfig(array $assignees): array
    {
        if ($assignees === []) {
            throw new InvalidWorkflowDefinitionException('Node is missing assignee configuration.');
        }

        return $this->normalizePhaseTwoAssignees($assignees);
    }

    /**
     * @param  array<string, mixed>  $assignees
     * @return array<string, mixed>
     */
    private function normalizePhaseTwoAssignees(array $assignees): array
    {
        $type = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE] ?? $assignees['type'] ?? null;

        if ($type === WorkflowDefinitionSchema::ASSIGNEE_TYPE_CALLBACK || $type === 'callback') {
            $resolverKey = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK]
                ?? $assignees['callback']
                ?? $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE]
                ?? $assignees['value']
                ?? null;

            if (! is_string($resolverKey) || $resolverKey === '') {
                throw new InvalidWorkflowDefinitionException('Callback assignee resolver key is required.');
            }

            return ['resolver' => $resolverKey];
        }

        if ($type === WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION || $type === 'permission') {
            $permissionKey = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE] ?? $assignees['value'] ?? null;

            if (! is_string($permissionKey) || $permissionKey === '') {
                throw new InvalidWorkflowDefinitionException('Permission assignee value is required.');
            }

            return ['resolver' => $permissionKey];
        }

        if ($type === WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER || $type === 'user') {
            $userId = (int) ($assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE] ?? $assignees['value'] ?? 0);

            if ($userId <= 0) {
                throw new InvalidWorkflowDefinitionException('User assignee value must be a valid user id.');
            }

            return [
                'type' => 'user_ids',
                'value' => [$userId],
            ];
        }

        throw new InvalidWorkflowDefinitionException('Unsupported assignee configuration.');
    }
}
