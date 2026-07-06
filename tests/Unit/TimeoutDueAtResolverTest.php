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

namespace DbflowLabs\Core\Tests\Unit;

use DbflowLabs\Core\Support\TimeoutDueAtResolver;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class TimeoutDueAtResolverTest extends TestCase
{
    #[Test]
    public function it_accepts_iso8601_durations(): void
    {
        $resolver = new TimeoutDueAtResolver;
        $from = Carbon::parse('2026-07-07 10:00:00');

        $this->assertTrue(TimeoutDueAtResolver::isValidDuration('P1D'));
        $this->assertTrue(TimeoutDueAtResolver::isValidDuration('PT24H'));

        $this->assertSame(
            '2026-07-08 10:00:00',
            $resolver->resolveDueAt('P1D', $from)?->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function it_rejects_invalid_durations(): void
    {
        $this->assertFalse(TimeoutDueAtResolver::isValidDuration(''));
        $this->assertFalse(TimeoutDueAtResolver::isValidDuration('1 day'));
        $this->assertFalse(TimeoutDueAtResolver::isValidDuration('P0D'));

        $this->expectException(InvalidArgumentException::class);
        TimeoutDueAtResolver::parseDuration('invalid');
    }
}
