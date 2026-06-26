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

namespace DbflowLabs\Core\Enums;

enum ApprovalMode: string
{
    case Any = 'any';
    case All = 'all';
    case Sequential = 'sequential';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Any one approves',
            self::All => 'All must approve',
            self::Sequential => 'Sequential approval',
        };
    }

    public function isAny(): bool
    {
        return $this === self::Any;
    }

    public function isAll(): bool
    {
        return $this === self::All;
    }

    public function isSequential(): bool
    {
        return $this === self::Sequential;
    }
}
