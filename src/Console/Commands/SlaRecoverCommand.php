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

use DbflowLabs\Core\Exceptions\WorkflowNotAvailableException;
use DbflowLabs\Core\Services\Sla\RecoverStaleSlaEvents;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Console\Command;

final class SlaRecoverCommand extends Command
{
    protected $signature = 'dbflow:sla-recover {--limit= : Maximum stale events to recover}';

    protected $description = 'Recover stale SLA events left in processing state';

    public function handle(RecoverStaleSlaEvents $recovery): int
    {
        try {
            DbflowRuntime::ensureEnabled();
        } catch (WorkflowNotAvailableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $limitValue = is_numeric($limit) ? (int) $limit : null;

        try {
            $result = $recovery->handle(limit: $limitValue);
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'SLA recovery complete. recovered=%d cancelled=%d exhausted=%d',
            $result['recovered'],
            $result['cancelled'],
            $result['exhausted'],
        ));

        return self::SUCCESS;
    }
}

