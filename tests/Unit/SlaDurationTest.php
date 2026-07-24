<?php

declare(strict_types=1);

namespace DbflowLabs\Core\Tests\Unit;

use DbflowLabs\Core\Sla\SlaDuration;
use DbflowLabs\Core\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

final class SlaDurationTest extends TestCase
{
    #[Test]
    public function it_accepts_hours_and_normalizes_days(): void
    {
        $this->assertSame(86400, SlaDuration::parse('PT24H')->totalSeconds());
        $this->assertSame('PT24H', SlaDuration::parse('P1D')->normalized());
        $this->assertSame(1800, SlaDuration::parse('PT30M')->totalSeconds());
    }

    #[Test]
    public function it_rejects_invalid_durations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SlaDuration::parse('P0D');
    }

    #[Test]
    public function it_rejects_months_and_years(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SlaDuration::parse('P1M');
    }
}
