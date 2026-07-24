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
use DbflowLabs\Core\Services\Actions\DispatchActionExecutions;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Console\Command;

final class ActionsDispatchCommand extends Command
{
    protected $signature = 'dbflow:actions-dispatch {--limit= : Maximum executions to claim} {--dry-run : List claimable executions without mutating state}';

    protected $description = 'Claim queued action executions and dispatch queue jobs for processing';

    public function handle(DispatchActionExecutions $dispatcher): int
    {
        try {
            DbflowRuntime::ensureEnabled();
        } catch (WorkflowNotAvailableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $limitValue = is_numeric($limit) ? (int) $limit : null;
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $dispatcher->handle(limit: $limitValue, dryRun: $dryRun);
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Action dispatch complete. claimed=%d dispatched=%d%s',
            $result['claimed'],
            $result['dispatched'],
            $dryRun ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }
}
