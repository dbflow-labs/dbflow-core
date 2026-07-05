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

use DbflowLabs\Core\Actions\SyncWorkflowDefinitions;
use Illuminate\Console\Command;

final class SyncWorkflowDefinitionsCommand extends Command
{
    protected $signature = 'dbflow:sync
                            {--workflow= : Sync only the given workflow key}
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Sync registered code-first workflow definitions into dbflow tables';

    public function handle(SyncWorkflowDefinitions $sync): int
    {
        $workflowKey = $this->option('workflow');
        $workflowKey = is_string($workflowKey) && $workflowKey !== '' ? $workflowKey : null;
        $dryRun = (bool) $this->option('dry-run');

        $summary = $sync->handle($workflowKey, $dryRun);

        $this->line('Created: '.(count($summary['created']) > 0 ? implode(', ', $summary['created']) : '(none)'));
        $this->line('Updated: '.(count($summary['updated']) > 0 ? implode(', ', $summary['updated']) : '(none)'));
        $this->line('Unchanged: '.(count($summary['unchanged']) > 0 ? implode(', ', $summary['unchanged']) : '(none)'));

        if ($dryRun) {
            $this->comment('Dry run only — no database changes were written.');
        }

        return self::SUCCESS;
    }
}
