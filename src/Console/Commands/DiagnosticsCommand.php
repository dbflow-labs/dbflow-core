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

namespace DbflowLabs\Core\Console\Commands;

use DbflowLabs\Core\Services\Diagnostics\RuntimeDiagnostics;
use Illuminate\Console\Command;

final class DiagnosticsCommand extends Command
{
    protected $signature = 'dbflow:diagnostics';

    protected $description = 'Report DBFlow runtime diagnostics for SLA, actions, capabilities, and queue readiness';

    public function handle(RuntimeDiagnostics $diagnostics): int
    {
        $report = $diagnostics->report();

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
