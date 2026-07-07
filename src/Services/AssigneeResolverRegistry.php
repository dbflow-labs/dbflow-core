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
     * @return list<int|string>
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
            $userId = $this->normalizeUserId($value);

            if ($userId === null || isset($seen[$userId])) {
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
     * Normalizes a raw assignee value into a user id. Supports positive integers and
     * non-empty string ids (numeric, UUID, ULID, etc.), matching the VARCHAR(64)
     * assignee_user_id column. Returns null when the value is not a valid user id.
     */
    private function normalizeUserId(mixed $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '' || mb_strlen($normalized) > WorkflowDefinitionSchema::ASSIGNEE_USER_ID_MAX_LENGTH) {
            return null;
        }

        return $normalized;
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
            $rawUserId = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE] ?? $assignees['value'] ?? null;

            if (! WorkflowDefinitionSchema::isValidUserAssigneeValue($rawUserId)) {
                throw new InvalidWorkflowDefinitionException('User assignee value must be a valid user id.');
            }

            return [
                'type' => 'user_ids',
                'value' => [is_string($rawUserId) ? trim($rawUserId) : $rawUserId],
            ];
        }

        throw new InvalidWorkflowDefinitionException('Unsupported assignee configuration.');
    }
}
