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

use DbflowLabs\Core\Definitions\Contracts\WorkflowNodeInterface;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\NodeType;

abstract class AbstractWorkflowNode implements WorkflowNodeInterface
{
    /**
     * Opaque UI payload reserved for canvas packages (e.g. dbflow-filament-pro).
     * Never consumed by the workflow execution engine.
     *
     * @var array<string, mixed>
     */
    protected array $metadata = [];

    /**
     * @param  array{x?: int|float|string, y?: int|float|string}|null  $position
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        protected readonly string $key,
        protected readonly string $name,
        protected readonly ?array $position = null,
        array $metadata = [],
    ) {
        $this->metadata = $metadata;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array{x: int, y: int}|null
     */
    public function position(): ?array
    {
        return $this->position;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    abstract public function type(): NodeType;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            WorkflowDefinitionSchema::FIELD_KEY => $this->key,
            WorkflowDefinitionSchema::FIELD_TYPE => $this->type()->value,
            WorkflowDefinitionSchema::FIELD_NAME => $this->name,
        ];

        if ($this->position !== null) {
            $payload[WorkflowDefinitionSchema::FIELD_POSITION] = $this->position;
        }

        if ($this->metadata !== []) {
            $payload[WorkflowDefinitionSchema::FIELD_METADATA] = $this->metadata;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{x: int, y: int}|null
     */
    protected static function hydratePosition(array $data): ?array
    {
        $position = $data[WorkflowDefinitionSchema::FIELD_POSITION] ?? null;

        if (! is_array($position)) {
            return null;
        }

        return [
            'x' => self::sanitizeCoordinate($position['x'] ?? 0),
            'y' => self::sanitizeCoordinate($position['y'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function hydrateMetadata(array $data): array
    {
        $metadata = $data[WorkflowDefinitionSchema::FIELD_METADATA] ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    protected static function sanitizeCoordinate(mixed $value): int
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
