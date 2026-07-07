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
use DbflowLabs\Core\Enums\NodeType;
use InvalidArgumentException;

final class ActionNode extends AbstractWorkflowNode
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        string $key,
        string $name,
        private readonly string $actionKey,
        private readonly ?array $payload = null,
        private readonly ?string $callback = null,
        private readonly bool $stopOnError = false,
        ?array $position = null,
        array $metadata = [],
    ) {
        parent::__construct($key, $name, $position, $metadata);
    }

    public function type(): NodeType
    {
        return NodeType::Action;
    }

    public function actionKey(): string
    {
        return $this->actionKey;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        return $this->payload;
    }

    public function callback(): ?string
    {
        return $this->callback;
    }

    /**
     * When true, a handler exception aborts traversal (via ActionExecutionFailedException)
     * instead of being logged as ActionFailed and swallowed so the workflow can proceed.
     */
    public function stopOnError(): bool
    {
        return $this->stopOnError;
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

        $actionKey = $config['action_key'] ?? $config['action'] ?? '';
        $actionKey = is_string($actionKey) ? $actionKey : (string) $actionKey;

        $payload = isset($config['payload']) && is_array($config['payload'])
            ? $config['payload']
            : null;

        $callback = isset($config['callback']) && is_string($config['callback']) && $config['callback'] !== ''
            ? $config['callback']
            : null;

        $stopOnError = isset($config['stop_on_error']) && $config['stop_on_error'] === true;

        return new self(
            key: $key,
            name: $name,
            actionKey: $actionKey,
            payload: $payload,
            callback: $callback,
            stopOnError: $stopOnError,
            position: self::hydratePosition($data),
            metadata: self::hydrateMetadata($data),
        );
    }

    public function toArray(): array
    {
        $payload = parent::toArray();

        $config = [
            'action_key' => $this->actionKey,
        ];

        if ($this->payload !== null) {
            $config['payload'] = $this->payload;
        }

        if ($this->callback !== null) {
            $config['callback'] = $this->callback;
        }

        if ($this->stopOnError) {
            $config['stop_on_error'] = true;
        }

        $payload[WorkflowDefinitionSchema::FIELD_CONFIG] = $config;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requireString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Action node field [{$field}] is required.");
        }

        return $value;
    }
}
