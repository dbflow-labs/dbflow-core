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

enum ActionExecutionMode: string
{
    case LegacySync = 'legacy_sync';
    case ReliableBlocking = 'reliable_blocking';
    case ReliableNonBlocking = 'reliable_non_blocking';

    public function isLegacy(): bool
    {
        return $this === self::LegacySync;
    }

    public function isReliable(): bool
    {
        return ! $this->isLegacy();
    }

    public static function normalize(mixed $value): self
    {
        if ($value === null || $value === '') {
            return self::LegacySync;
        }

        if (! is_string($value)) {
            throw new \InvalidArgumentException('Action execution_mode must be a string when provided.');
        }

        $mode = self::tryFrom($value);

        if ($mode === null) {
            throw new \InvalidArgumentException(
                'Action execution_mode must be one of: legacy_sync, reliable_blocking, reliable_non_blocking.',
            );
        }

        return $mode;
    }
}
