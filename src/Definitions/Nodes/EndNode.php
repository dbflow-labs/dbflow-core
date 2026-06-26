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

final class EndNode extends AbstractWorkflowNode
{
    public function __construct(
        string $key,
        string $name,
        private readonly ?string $status = null,
        ?array $position = null,
        array $metadata = [],
    ) {
        parent::__construct($key, $name, $position, $metadata);
    }

    public function type(): NodeType
    {
        return NodeType::End;
    }

    public function status(): ?string
    {
        return $this->status;
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

        $status = $config[WorkflowDefinitionSchema::CONFIG_STATUS] ?? null;
        $status = is_string($status) && $status !== '' ? $status : null;

        return new self(
            key: $key,
            name: $name,
            status: $status,
            position: self::hydratePosition($data),
            metadata: self::hydrateMetadata($data),
        );
    }

    public function toArray(): array
    {
        $payload = parent::toArray();

        if ($this->status !== null) {
            $payload[WorkflowDefinitionSchema::FIELD_CONFIG] = [
                WorkflowDefinitionSchema::CONFIG_STATUS => $this->status,
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requireString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("End node field [{$field}] is required.");
        }

        return $value;
    }
}
