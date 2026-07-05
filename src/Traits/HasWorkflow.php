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

namespace DbflowLabs\Core\Traits;

use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Support\DbflowAuth;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasWorkflow
{
    protected static function bootHasWorkflow(): void
    {
        if (! DbflowRuntime::isEnabled() || config('dbflow.binding_mode') !== 'ui') {
            return;
        }

        static::created(function (Model $model): void {
            $modelClass = get_class($model);

            Workflow::query()
                ->where('model_type', $modelClass)
                ->get()
                ->filter(static fn (Workflow $workflow): bool => $workflow->canStartNewInstances())
                ->each(static function (Workflow $workflow) use ($model): void {
                    DBFlow::start($workflow->key, $model, DbflowAuth::currentUser());
                });
        });
    }

    public function startWorkflow(string $key): WorkflowInstance
    {
        return DBFlow::start($key, $this, DbflowAuth::currentUser());
    }

    /**
     * @return MorphMany<WorkflowInstance, $this>
     */
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'workflowable');
    }

    public function latestWorkflowInstance(?string $workflowKey = null): ?WorkflowInstance
    {
        return $this->filteredWorkflowInstancesQuery($workflowKey)
            ->latest('id')
            ->first();
    }

    public function runningWorkflowInstance(?string $workflowKey = null): ?WorkflowInstance
    {
        return $this->filteredWorkflowInstancesQuery($workflowKey)
            ->where('status', WorkflowInstanceStatus::Running)
            ->latest('id')
            ->first();
    }

    public function hasRunningWorkflow(?string $workflowKey = null): bool
    {
        return $this->filteredWorkflowInstancesQuery($workflowKey)
            ->where('status', WorkflowInstanceStatus::Running)
            ->exists();
    }

    public function completedWorkflowInstance(?string $workflowKey = null): ?WorkflowInstance
    {
        return $this->filteredWorkflowInstancesQuery($workflowKey)
            ->whereIn('status', [
                WorkflowInstanceStatus::Approved,
                WorkflowInstanceStatus::Rejected,
                WorkflowInstanceStatus::Cancelled,
            ])
            ->latest('id')
            ->first();
    }

    /**
     * @return Builder<WorkflowLog>
     */
    public function workflowLogs(?string $workflowKey = null): Builder
    {
        return WorkflowLog::query()
            ->whereHas('workflowInstance', function (Builder $query) use ($workflowKey): void {
                $query
                    ->where('workflowable_type', $this->getMorphClass())
                    ->where('workflowable_id', $this->getKey());

                if ($workflowKey !== null) {
                    $query->whereHas('workflow', fn (Builder $workflowQuery) => $workflowQuery->where('key', $workflowKey));
                }
            })
            ->latest('created_at');
    }

    /**
     * @return MorphMany<WorkflowInstance, $this>
     */
    protected function filteredWorkflowInstancesQuery(?string $workflowKey = null): MorphMany
    {
        $query = $this->workflowInstances();

        if ($workflowKey !== null) {
            $query->whereHas('workflow', static function ($workflowQuery) use ($workflowKey): void {
                $workflowQuery->where('key', $workflowKey);
            });
        }

        return $query;
    }
}
