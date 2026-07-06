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

namespace DbflowLabs\Core\Definitions;

final class WorkflowDefinitionSchema
{
    public const NODE_TYPE_START = 'start';

    public const NODE_TYPE_APPROVAL = 'approval';

    public const NODE_TYPE_CONDITION = 'condition';

    public const NODE_TYPE_ACTION = 'action';

    public const NODE_TYPE_END = 'end';

    public const APPROVAL_MODE_ANY = 'any';

    public const APPROVAL_MODE_ALL = 'all';

    public const APPROVAL_MODE_SEQUENTIAL = 'sequential';

    public const ASSIGNEE_TYPE_USER = 'user';

    public const ASSIGNEE_TYPE_ROLE = 'role';

    public const ASSIGNEE_TYPE_PERMISSION = 'permission';

    public const ASSIGNEE_TYPE_CALLBACK = 'callback';

    public const END_NODE_STATUS_COMPLETED = 'completed';

    public const END_NODE_STATUS_APPROVED = 'approved';

    public const END_NODE_STATUS_REJECTED = 'rejected';

    public const END_NODE_STATUS_CANCELLED = 'cancelled';

    public const FIELD_KEY = 'key';

    public const FIELD_NAME = 'name';

    public const FIELD_VERSION = 'version';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_DESCRIPTION = 'description';

    public const FIELD_METADATA = 'metadata';

    public const FIELD_NODES = 'nodes';

    public const FIELD_TRANSITIONS = 'transitions';

    public const FIELD_TYPE = 'type';

    public const FIELD_CONFIG = 'config';

    public const FIELD_POSITION = 'position';

    public const FIELD_FROM = 'from';

    public const FIELD_TO = 'to';

    public const FIELD_CONDITION = 'condition';

    public const FIELD_PRIORITY = 'priority';

    public const FIELD_IS_DEFAULT = 'is_default';

    public const CONFIG_APPROVAL_MODE = 'approval_mode';

    public const CONFIG_ASSIGNEES = 'assignees';

    public const CONFIG_TIMEOUT = 'timeout';

    public const CONFIG_EXPRESSION = 'expression';

    public const CONFIG_STATUS = 'status';

    public const ASSIGNEE_FIELD_TYPE = 'type';

    public const ASSIGNEE_FIELD_VALUE = 'value';

    public const ASSIGNEE_FIELD_CALLBACK = 'callback';

    public const TIMEOUT_DUE_IN = 'due_in';

    public const TIMEOUT_ON_TIMEOUT = 'on_timeout';

    public const KEY_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * @return list<string>
     */
    public static function nodeTypes(): array
    {
        return [
            self::NODE_TYPE_START,
            self::NODE_TYPE_APPROVAL,
            self::NODE_TYPE_CONDITION,
            self::NODE_TYPE_ACTION,
            self::NODE_TYPE_END,
        ];
    }

    /**
     * @return list<string>
     */
    public static function approvalModes(): array
    {
        return [
            self::APPROVAL_MODE_ANY,
            self::APPROVAL_MODE_ALL,
            self::APPROVAL_MODE_SEQUENTIAL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function assigneeTypes(): array
    {
        return [
            self::ASSIGNEE_TYPE_USER,
            self::ASSIGNEE_TYPE_ROLE,
            self::ASSIGNEE_TYPE_PERMISSION,
            self::ASSIGNEE_TYPE_CALLBACK,
        ];
    }

    /**
     * Assignee types the open-core runtime can resolve without additional host integration.
     *
     * @return list<string>
     */
    public static function runtimeSupportedAssigneeTypes(): array
    {
        return [
            self::ASSIGNEE_TYPE_USER,
            self::ASSIGNEE_TYPE_PERMISSION,
            self::ASSIGNEE_TYPE_CALLBACK,
        ];
    }

    /**
     * @return list<string>
     */
    public static function endNodeStatuses(): array
    {
        return [
            self::END_NODE_STATUS_COMPLETED,
            self::END_NODE_STATUS_APPROVED,
            self::END_NODE_STATUS_REJECTED,
            self::END_NODE_STATUS_CANCELLED,
        ];
    }

    /**
     * Alpha condition routing contract: predicates are evaluated from outgoing Transition.condition.
     * ConditionNode.expression is optional documentation only and is not used by TransitionResolver.
     */
    public static function conditionPredicateField(): string
    {
        return self::FIELD_CONDITION;
    }

    public static function isValidUserAssigneeValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_int($value)) {
            return $value > 0;
        }

        if (! is_string($value)) {
            return false;
        }

        $normalized = trim($value);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return false;
        }

        return (int) $normalized > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyDefinition(): array
    {
        return [
            self::FIELD_KEY => '',
            self::FIELD_NAME => '',
            self::FIELD_SCHEMA_VERSION => '1.0',
            self::FIELD_NODES => [],
            self::FIELD_TRANSITIONS => [],
        ];
    }
}
