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

namespace DbflowLabs\Core\Models;

use DbflowLabs\Core\Enums\AssignmentSource;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Support\DbflowAuth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static Builder<static> actionableBy(string $userId)
 */
class WorkflowTaskAssignment extends Model
{
    protected $table = 'dbflow_workflow_task_assignments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_task_id',
        'assignee_user_id',
        'original_assignee_user_id',
        'effective_assignee_user_id',
        'status',
        'assignment_source',
        'delegation_id',
        'previous_assignment_id',
        'reassignment_operation_key',
        'sequence',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'workflow_task_id' => 'integer',
            'assignee_user_id' => 'string',
            'original_assignee_user_id' => 'string',
            'effective_assignee_user_id' => 'string',
            'status' => WorkflowTaskAssignmentStatus::class,
            'assignment_source' => AssignmentSource::class,
            'delegation_id' => 'integer',
            'previous_assignment_id' => 'integer',
            'sequence' => 'integer',
            'acted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowTask, $this>
     */
    public function workflowTask(): BelongsTo
    {
        return $this->belongsTo(WorkflowTask::class, 'workflow_task_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(DbflowAuth::userModelClass(), 'assignee_user_id');
    }

    /**
     * @return BelongsTo<WorkflowDelegation, $this>
     */
    public function delegation(): BelongsTo
    {
        return $this->belongsTo(WorkflowDelegation::class, 'delegation_id');
    }

    public function originalAssigneeUserId(): string
    {
        $original = $this->original_assignee_user_id;

        if (is_string($original) && $original !== '') {
            return $original;
        }

        return (string) $this->assignee_user_id;
    }

    public function effectiveAssigneeUserId(): string
    {
        $effective = $this->effective_assignee_user_id;

        if (is_string($effective) && $effective !== '') {
            return $effective;
        }

        return (string) $this->assignee_user_id;
    }

    public function assignmentSourceOrDirect(): AssignmentSource
    {
        return $this->assignment_source ?? AssignmentSource::Direct;
    }

    public function isActionableBy(string $userId): bool
    {
        if ($this->status !== WorkflowTaskAssignmentStatus::Pending) {
            return false;
        }

        if ($this->effectiveAssigneeUserId() === $userId) {
            return true;
        }

        if ((string) $this->assignee_user_id !== $userId) {
            return false;
        }

        $source = $this->assignmentSourceOrDirect();

        return $source !== AssignmentSource::Reassignment
            && $source !== AssignmentSource::Escalation;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function constrainActionableBy(Builder $query, string $userId): Builder
    {
        return $query->where(function (Builder $builder) use ($userId): void {
            $builder
                ->where('effective_assignee_user_id', $userId)
                ->orWhere(function (Builder $assigneeMatch) use ($userId): void {
                    $assigneeMatch
                        ->where('assignee_user_id', $userId)
                        ->where(function (Builder $sourceFilter): void {
                            $sourceFilter
                                ->whereNull('assignment_source')
                                ->orWhereNotIn('assignment_source', [
                                    AssignmentSource::Reassignment->value,
                                    AssignmentSource::Escalation->value,
                                ]);
                        });
                });
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActionableBy(Builder $query, string $userId): Builder
    {
        return self::constrainActionableBy($query, $userId);
    }
}
