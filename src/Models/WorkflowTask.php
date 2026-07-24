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

use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowTask extends Model
{
    protected $table = 'dbflow_workflow_tasks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_instance_id',
        'iteration',
        'node_key',
        'node_name',
        'status',
        'approval_mode',
        'due_at',
        'overdue_at',
        'sla_policy_snapshot',
        'sla_policy_source',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'workflow_instance_id' => 'integer',
            'iteration' => 'integer',
            'status' => WorkflowTaskStatus::class,
            'approval_mode' => ApprovalMode::class,
            'due_at' => 'datetime',
            'overdue_at' => 'datetime',
            'sla_policy_snapshot' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    /**
     * @return HasMany<WorkflowTaskAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkflowTaskAssignment::class, 'workflow_task_id');
    }

    /**
     * @return HasMany<WorkflowLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class, 'workflow_task_id');
    }
}
