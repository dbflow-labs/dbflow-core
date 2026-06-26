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

final class StartNode extends AbstractWorkflowNode
{
    public function type(): NodeType
    {
        return NodeType::Start;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $key = self::requireString($data, WorkflowDefinitionSchema::FIELD_KEY);
        $name = self::requireString($data, WorkflowDefinitionSchema::FIELD_NAME);

        return new self(
            key: $key,
            name: $name,
            position: self::hydratePosition($data),
            metadata: self::hydrateMetadata($data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requireString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Start node field [{$field}] is required.");
        }

        return $value;
    }
}
