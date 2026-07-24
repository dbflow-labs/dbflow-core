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
use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Enums\NodeType;
use InvalidArgumentException;

final class ActionNode extends AbstractWorkflowNode
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $retry
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
        private readonly ActionExecutionMode $executionMode = ActionExecutionMode::LegacySync,
        private readonly bool $allowManualSkip = false,
        private readonly ?int $maxAttempts = null,
        private readonly ?array $retry = null,
        private readonly ?int $timeoutSeconds = null,
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

    public function executionMode(): ActionExecutionMode
    {
        return $this->executionMode;
    }

    public function allowManualSkip(): bool
    {
        return $this->allowManualSkip;
    }

    public function maxAttempts(): ?int
    {
        return $this->maxAttempts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function retry(): ?array
    {
        return $this->retry;
    }

    public function timeoutSeconds(): ?int
    {
        return $this->timeoutSeconds;
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

        try {
            $executionMode = ActionExecutionMode::normalize(
                $config[WorkflowDefinitionSchema::CONFIG_EXECUTION_MODE] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'Action node config.execution_mode is invalid: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        $allowManualSkip = ($config[WorkflowDefinitionSchema::CONFIG_ALLOW_MANUAL_SKIP] ?? false) === true;
        $maxAttempts = self::requireOptionalPositiveInt(
            $config,
            WorkflowDefinitionSchema::CONFIG_MAX_ATTEMPTS,
            'Action node config.max_attempts',
        );
        $timeoutSeconds = self::requireOptionalPositiveInt(
            $config,
            WorkflowDefinitionSchema::CONFIG_ACTION_TIMEOUT,
            'Action node config.timeout_seconds',
        );

        $retry = null;

        if (array_key_exists(WorkflowDefinitionSchema::CONFIG_RETRY, $config)) {
            $rawRetry = $config[WorkflowDefinitionSchema::CONFIG_RETRY];

            if (! is_array($rawRetry) || $rawRetry === []) {
                throw new InvalidArgumentException(
                    'Action node config.retry must be a non-empty object when provided.',
                );
            }

            $retry = $rawRetry;
        }

        return new self(
            key: $key,
            name: $name,
            actionKey: $actionKey,
            payload: $payload,
            callback: $callback,
            stopOnError: $stopOnError,
            position: self::hydratePosition($data),
            metadata: self::hydrateMetadata($data),
            executionMode: $executionMode,
            allowManualSkip: $allowManualSkip,
            maxAttempts: $maxAttempts,
            retry: $retry,
            timeoutSeconds: $timeoutSeconds,
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

        // Preserve v1.0 export shape: omit legacy_sync mode and unset reliable fields.
        if (! $this->executionMode->isLegacy()) {
            $config[WorkflowDefinitionSchema::CONFIG_EXECUTION_MODE] = $this->executionMode->value;
        }

        if ($this->allowManualSkip) {
            $config[WorkflowDefinitionSchema::CONFIG_ALLOW_MANUAL_SKIP] = true;
        }

        if ($this->maxAttempts !== null) {
            $config[WorkflowDefinitionSchema::CONFIG_MAX_ATTEMPTS] = $this->maxAttempts;
        }

        if ($this->retry !== null) {
            $config[WorkflowDefinitionSchema::CONFIG_RETRY] = $this->retry;
        }

        if ($this->timeoutSeconds !== null) {
            $config[WorkflowDefinitionSchema::CONFIG_ACTION_TIMEOUT] = $this->timeoutSeconds;
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

    /**
     * @param  array<string, mixed>  $config
     */
    private static function requireOptionalPositiveInt(array $config, string $field, string $label): ?int
    {
        if (! array_key_exists($field, $config)) {
            return null;
        }

        $value = $config[$field];

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException("{$label} must be a positive integer when provided.");
    }
}
