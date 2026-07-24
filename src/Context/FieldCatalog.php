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

use DbflowLabs\Core\Contracts\FieldCatalogProvider;
use DbflowLabs\Core\Enums\ContextNamespace;
use DbflowLabs\Core\Exceptions\InvalidWorkflowContextException;

final class FieldCatalog
{
    /**
     * @param  list<FieldDefinition>  $definitions
     */
    public function __construct(
        private readonly array $definitions,
    ) {
        $seen = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof FieldDefinition) {
                throw new InvalidWorkflowContextException('Field catalog entries must be FieldDefinition instances.');
            }

            $path = $definition->path();

            if (isset($seen[$path])) {
                throw new InvalidWorkflowContextException("Duplicate field catalog path [{$path}].");
            }

            $root = explode('.', $path, 2)[0];
            $namespace = ContextNamespace::tryFrom($root);

            if ($namespace === null) {
                throw new InvalidWorkflowContextException(
                    "Field path [{$path}] must begin with an allowed context namespace.",
                );
            }

            $seen[$path] = true;
        }
    }

    public static function fromProvider(FieldCatalogProvider $provider): self
    {
        return new self($provider->fieldDefinitions());
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     */
    public static function fromArrays(array $definitions): self
    {
        return new self(array_map(
            static fn (array $definition): FieldDefinition => FieldDefinition::fromArray($definition),
            $definitions,
        ));
    }

    /**
     * @return list<FieldDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function find(string $path): ?FieldDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->path() === $path) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return array<string, bool>
     */
    public function sensitivePathMap(): array
    {
        $map = [];

        foreach ($this->definitions as $definition) {
            if ($definition->isSensitive()) {
                $map[$definition->path()] = true;
            }
        }

        return $map;
    }
}
