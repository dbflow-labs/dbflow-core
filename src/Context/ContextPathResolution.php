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

final class ContextPathResolution
{
    public const STATUS_FOUND = 'found';

    public const STATUS_NULL = 'present_null';

    public const STATUS_MISSING = 'missing';

    private function __construct(
        private readonly string $status,
        private readonly mixed $value = null,
    ) {}

    public static function found(mixed $value): self
    {
        return new self(self::STATUS_FOUND, $value);
    }

    public static function presentNull(): self
    {
        return new self(self::STATUS_NULL, null);
    }

    public static function missing(): self
    {
        return new self(self::STATUS_MISSING);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function isFound(): bool
    {
        return $this->status === self::STATUS_FOUND;
    }

    public function isPresentNull(): bool
    {
        return $this->status === self::STATUS_NULL;
    }

    public function isMissing(): bool
    {
        return $this->status === self::STATUS_MISSING;
    }
}
