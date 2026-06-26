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

use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Support\DbflowAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTaskAssignment extends Model
{
    protected $table = 'dbflow_workflow_task_assignments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_task_id',
        'assignee_user_id',
        'status',
        'sequence',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'workflow_task_id' => 'integer',
            'assignee_user_id' => 'integer',
            'status' => WorkflowTaskAssignmentStatus::class,
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
}
