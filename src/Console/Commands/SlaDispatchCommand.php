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
use DbflowLabs\Core\Services\Sla\DispatchSlaEvents;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Console\Command;

final class SlaDispatchCommand extends Command
{
    protected $signature = 'dbflow:sla-dispatch {--limit= : Maximum events to claim} {--type= : reminder|overdue|escalation} {--dry-run : List claimable events without mutating state}';

    protected $description = 'Claim due SLA events and dispatch queue jobs for processing';

    public function handle(DispatchSlaEvents $dispatcher): int
    {
        try {
            DbflowRuntime::ensureEnabled();
        } catch (WorkflowNotAvailableException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $type = $this->option('type');
        $eventType = is_string($type) && $type !== ''
            ? \DbflowLabs\Core\Enums\SlaEventType::tryFrom($type)
            : null;

        if (is_string($type) && $type !== '' && $eventType === null) {
            $this->error('Invalid --type value. Use reminder, overdue, or escalation.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $limitValue = is_numeric($limit) ? (int) $limit : null;
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $dispatcher->handle(
                limit: $limitValue,
                type: $eventType,
                dryRun: $dryRun,
            );
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'SLA dispatch complete. claimed=%d dispatched=%d%s',
            $result['claimed'],
            $result['dispatched'],
            $dryRun ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }
}

