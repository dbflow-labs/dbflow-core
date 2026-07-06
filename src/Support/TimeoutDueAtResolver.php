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

namespace DbflowLabs\Core\Support;

use Carbon\CarbonInterface;
use DateInterval;
use Exception;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class TimeoutDueAtResolver
{
    public static function isValidDuration(string $dueIn): bool
    {
        try {
            self::parseDuration($dueIn);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function resolveDueAt(?string $dueIn, ?CarbonInterface $from = null): ?Carbon
    {
        if ($dueIn === null || $dueIn === '') {
            return null;
        }

        $interval = self::parseDuration($dueIn);
        $base = $from instanceof Carbon ? $from->copy() : Carbon::parse($from ?? now());

        return $base->add($interval);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function parseDuration(string $dueIn): DateInterval
    {
        $dueIn = trim($dueIn);

        if ($dueIn === '' || ! str_starts_with($dueIn, 'P')) {
            throw new InvalidArgumentException('Timeout due_in must be a non-empty ISO 8601 duration starting with P.');
        }

        try {
            $interval = new DateInterval($dueIn);
        } catch (Exception) {
            throw new InvalidArgumentException("Timeout due_in [{$dueIn}] is not a valid ISO 8601 duration.");
        }

        if (self::isZeroDuration($interval)) {
            throw new InvalidArgumentException('Timeout due_in must represent a positive duration.');
        }

        return $interval;
    }

    private static function isZeroDuration(DateInterval $interval): bool
    {
        return $interval->y === 0
            && $interval->m === 0
            && $interval->d === 0
            && $interval->h === 0
            && $interval->i === 0
            && $interval->s === 0
            && $interval->f === 0.0;
    }
}
