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

use DbflowLabs\Core\Support\WorkflowDefinitionJsonFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionJsonFormatterTest extends TestCase
{
    #[Test]
    public function format_returns_pretty_printed_json(): void
    {
        $formatter = new WorkflowDefinitionJsonFormatter();

        $json = $formatter->format([
            'key' => 'json_flow',
            'name' => 'JSON Flow',
        ]);

        $this->assertStringContainsString("\"key\": \"json_flow\"", $json);
        $this->assertSame(
            [
                'key' => 'json_flow',
                'name' => 'JSON Flow',
            ],
            json_decode($json, true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
