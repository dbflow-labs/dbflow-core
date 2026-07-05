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

use DbflowLabs\Core\Enums\WorkflowStatus;
use DbflowLabs\Core\Exceptions\WorkflowInvalidStateException;
use DbflowLabs\Core\Models\Workflow;
use Illuminate\Support\Facades\DB;

final class EnableWorkflow
{
    public function handle(Workflow $workflow, int|string|null $updatedBy = null): Workflow
    {
        return DB::transaction(function () use ($workflow, $updatedBy): Workflow {
            $workflow = Workflow::query()->whereKey($workflow->getKey())->lockForUpdate()->firstOrFail();

            if ($workflow->isArchived()) {
                throw new WorkflowInvalidStateException('Archived workflows cannot be enabled.');
            }

            if (! $workflow->isDisabled()) {
                throw new WorkflowInvalidStateException('Only disabled workflows can be enabled.');
            }

            $workflow->status = $workflow->hasPublishedVersion()
                ? WorkflowStatus::Published->value
                : WorkflowStatus::Draft->value;
            $workflow->is_enabled = true;

            if ($updatedBy !== null) {
                $workflow->draft_updated_by = $updatedBy;
            }

            $workflow->save();

            return $workflow->refresh();
        });
    }
}
