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

namespace DbflowLabs\Core\Actions;

use DbflowLabs\Core\Exceptions\WorkflowInvalidStateException;
use DbflowLabs\Core\Models\Workflow;
use Illuminate\Support\Facades\DB;

final class DeleteWorkflow
{
    public function handle(Workflow $workflow): void
    {
        DB::transaction(function () use ($workflow): void {
            $locked = Workflow::query()->whereKey($workflow->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->canBeDeleted()) {
                throw new WorkflowInvalidStateException(
                    (string) __('dbflow.pages.workflows.notifications.workflow_delete_blocked_by_instances'),
                );
            }

            if ($locked->current_version_id !== null) {
                $locked->current_version_id = null;
                $locked->saveQuietly();
            }

            $locked->delete();
        });
    }
}
