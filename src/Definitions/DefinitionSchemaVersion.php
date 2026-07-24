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

use InvalidArgumentException;

/**
 * Explicit schema version object for workflow definitions.
 *
 * Missing schema_version normalizes to V1_0 without rewriting stored JSON.
 */
final class DefinitionSchemaVersion
{
    public const V1_0 = '1.0';

    public const V1_1 = '1.1';

    private function __construct(
        private readonly string $value,
    ) {}

    public static function v10(): self
    {
        return new self(self::V1_0);
    }

    public static function v11(): self
    {
        return new self(self::V1_1);
    }

    public static function fromMixed(mixed $value): self
    {
        if ($value === null || $value === '') {
            return self::v10();
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('schema_version must be a string when provided.');
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return self::v10();
        }

        if (! in_array($normalized, [self::V1_0, self::V1_1], true)) {
            throw new InvalidArgumentException(
                "Unsupported schema_version [{$normalized}]. Supported versions: 1.0, 1.1.",
            );
        }

        return new self($normalized);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isV10(): bool
    {
        return $this->value === self::V1_0;
    }

    public function isV11(): bool
    {
        return $this->value === self::V1_1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
