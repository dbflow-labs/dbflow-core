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

namespace DbflowLabs\Core\Context;

use DbflowLabs\Core\Enums\ContextNamespace;
use DbflowLabs\Core\Enums\FieldOperator;
use DbflowLabs\Core\Enums\FieldType;
use DbflowLabs\Core\Exceptions\InvalidWorkflowContextException;

final class FieldDefinition
{
    /**
     * @param  list<FieldOperator>  $allowedOperators
     * @param  list<string|int|float|bool>|null  $enumValues
     */
    public function __construct(
        private readonly string $path,
        private readonly FieldType $type,
        private readonly string $label,
        private readonly bool $nullable = true,
        private readonly bool $sensitive = false,
        private readonly array $allowedOperators = [],
        private readonly ?string $description = null,
        private readonly ?array $enumValues = null,
        private readonly ?ContextNamespace $sourceNamespace = null,
    ) {
        if ($path === '' || ! preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $path)) {
            throw new InvalidWorkflowContextException(
                "Field path [{$path}] must be a safe lowercase dot-path.",
            );
        }

        $root = explode('.', $path, 2)[0];
        $namespace = ContextNamespace::tryFrom($root);

        if ($namespace === null) {
            throw new InvalidWorkflowContextException(
                "Field path [{$path}] must begin with an allowed context namespace.",
            );
        }

        if ($namespace->isSystemManaged() && ($this->sourceNamespace === null || $this->sourceNamespace !== $namespace)) {
            // Host catalogs may describe system fields for UI, but cannot redefine ownership.
        }

        if ($type === FieldType::Enum && ($enumValues === null || $enumValues === [])) {
            throw new InvalidWorkflowContextException(
                "Enum field [{$path}] must declare allowed values.",
            );
        }

        foreach ($allowedOperators as $operator) {
            if (! $operator->isAllowedFor($type)) {
                throw new InvalidWorkflowContextException(
                    "Operator [{$operator->value}] is not valid for field type [{$type->value}] on path [{$path}].",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $path = is_string($data['path'] ?? null) ? $data['path'] : '';
        $typeValue = is_string($data['type'] ?? null) ? $data['type'] : '';
        $type = FieldType::tryFrom($typeValue);

        if ($type === null) {
            throw new InvalidWorkflowContextException("Field type [{$typeValue}] is not supported.");
        }

        $label = is_string($data['label'] ?? null) ? $data['label'] : $path;
        $operators = [];

        if (isset($data['allowed_operators']) && is_array($data['allowed_operators'])) {
            foreach ($data['allowed_operators'] as $operatorValue) {
                if (! is_string($operatorValue)) {
                    throw new InvalidWorkflowContextException('Field allowed_operators must contain strings.');
                }

                $operator = FieldOperator::tryFrom($operatorValue);

                if ($operator === null) {
                    throw new InvalidWorkflowContextException("Unknown field operator [{$operatorValue}].");
                }

                $operators[] = $operator;
            }
        } else {
            $operators = FieldOperator::forType($type);
        }

        $enumValues = null;

        if (isset($data['enum_values']) && is_array($data['enum_values'])) {
            $enumValues = [];

            foreach ($data['enum_values'] as $enumValue) {
                if (! is_scalar($enumValue)) {
                    throw new InvalidWorkflowContextException('Enum values must be scalar.');
                }

                $enumValues[] = $enumValue;
            }
        }

        $sourceNamespace = null;

        if (isset($data['source_namespace']) && is_string($data['source_namespace'])) {
            $sourceNamespace = ContextNamespace::tryFrom($data['source_namespace']);

            if ($sourceNamespace === null) {
                throw new InvalidWorkflowContextException(
                    "Unknown source_namespace [{$data['source_namespace']}].",
                );
            }
        }

        return new self(
            path: $path,
            type: $type,
            label: $label,
            nullable: ($data['nullable'] ?? true) !== false,
            sensitive: ($data['sensitive'] ?? false) === true,
            allowedOperators: $operators,
            description: isset($data['description']) && is_string($data['description'])
                ? $data['description']
                : null,
            enumValues: $enumValues,
            sourceNamespace: $sourceNamespace,
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    public function type(): FieldType
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive;
    }

    /**
     * @return list<FieldOperator>
     */
    public function allowedOperators(): array
    {
        return $this->allowedOperators !== []
            ? $this->allowedOperators
            : FieldOperator::forType($this->type);
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<string|int|float|bool>|null
     */
    public function enumValues(): ?array
    {
        return $this->enumValues;
    }

    public function sourceNamespace(): ?ContextNamespace
    {
        return $this->sourceNamespace;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type->value,
            'label' => $this->label,
            'nullable' => $this->nullable,
            'sensitive' => $this->sensitive,
            'allowed_operators' => array_map(
                static fn (FieldOperator $operator): string => $operator->value,
                $this->allowedOperators(),
            ),
            'description' => $this->description,
            'enum_values' => $this->enumValues,
            'source_namespace' => $this->sourceNamespace?->value,
        ];
    }
}
