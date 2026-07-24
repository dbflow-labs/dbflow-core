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

namespace DbflowLabs\Core\Sla;

use Carbon\CarbonInterface;
use DateInterval;
use Exception;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Fixed elapsed-time duration parser for SLA policies.
 */
final class SlaDuration
{
    private function __construct(
        private readonly int $totalSeconds,
        private readonly string $normalized,
    ) {}

    public static function parse(string $duration): self
    {
        $duration = trim($duration);

        if ($duration === '' || ! str_starts_with($duration, 'P')) {
            throw new InvalidArgumentException('SLA duration must be a non-empty ISO 8601 duration starting with P.');
        }

        if (preg_match('/\dY/i', $duration) === 1) {
            throw new InvalidArgumentException('SLA duration must not contain calendar years.');
        }

        if (preg_match('/P[^T]*\dM/i', $duration) === 1) {
            throw new InvalidArgumentException('SLA duration must not contain calendar months.');
        }

        try {
            $interval = new DateInterval($duration);
        } catch (Exception) {
            throw new InvalidArgumentException("SLA duration [{$duration}] is not a valid ISO 8601 duration.");
        }

        if (self::isZeroInterval($interval)) {
            throw new InvalidArgumentException('SLA duration must be positive.');
        }

        $totalSeconds = self::intervalToSeconds($interval);

        if ($totalSeconds <= 0) {
            throw new InvalidArgumentException('SLA duration must be positive.');
        }

        $min = (int) config('dbflow.sla.min_duration_seconds', 60);
        $max = (int) config('dbflow.sla.max_duration_seconds', 31536000);

        if ($totalSeconds < $min) {
            throw new InvalidArgumentException("SLA duration must be at least {$min} seconds.");
        }

        if ($totalSeconds > $max) {
            throw new InvalidArgumentException("SLA duration must not exceed {$max} seconds.");
        }

        return new self($totalSeconds, self::normalizeToSecondsRepresentation($interval, $duration));
    }

    public static function isValid(string $duration): bool
    {
        try {
            self::parse($duration);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function totalSeconds(): int
    {
        return $this->totalSeconds;
    }

    public function normalized(): string
    {
        return $this->normalized;
    }

    public function addTo(CarbonInterface $base): Carbon
    {
        $result = $base instanceof Carbon ? $base->copy() : Carbon::parse($base);

        return $result->utc()->addSeconds($this->totalSeconds);
    }

    public function subtractFrom(CarbonInterface $base): Carbon
    {
        $result = $base instanceof Carbon ? $base->copy() : Carbon::parse($base);

        return $result->utc()->subSeconds($this->totalSeconds);
    }

    private static function intervalToSeconds(DateInterval $interval): int
    {
        $days = (int) $interval->d + ((int) ($interval->w ?? 0) * 7);

        return ($days * 86400)
            + ((int) $interval->h * 3600)
            + ((int) $interval->i * 60)
            + (int) $interval->s;
    }

    private static function isZeroInterval(DateInterval $interval): bool
    {
        return (int) $interval->y === 0
            && (int) $interval->m === 0
            && (int) $interval->d === 0
            && (int) $interval->h === 0
            && (int) $interval->i === 0
            && (int) $interval->s === 0
            && (int) ($interval->w ?? 0) === 0
            && (float) $interval->f === 0.0;
    }

    private static function normalizeToSecondsRepresentation(DateInterval $interval, string $original): string
    {
        if (preg_match('/^P(\d+)D$/i', $original) === 1) {
            $days = (int) $interval->d;

            return 'PT'.($days * 24).'H';
        }

        return strtoupper($original);
    }
}

