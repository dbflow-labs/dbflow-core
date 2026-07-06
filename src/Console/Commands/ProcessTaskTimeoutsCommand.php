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

use DbflowLabs\Core\Actions\ProcessTaskTimeouts;
use DbflowLabs\Core\Exceptions\WorkflowNotAvailableException;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Console\Command;

final class ProcessTaskTimeoutsCommand extends Command
{
    protected $signature = 'dbflow:process-timeouts';

    protected $description = 'Process overdue workflow tasks according to node timeout configuration';

    public function handle(): int
    {
        try {
            DbflowRuntime::ensureEnabled();
        } catch (WorkflowNotAvailableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $processed = app(ProcessTaskTimeouts::class)->handle();

        $this->info("Processed {$processed} timed-out task(s).");

        return self::SUCCESS;
    }
}
