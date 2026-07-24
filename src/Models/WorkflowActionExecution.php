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

use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Enums\ActionExecutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowActionExecution extends Model
{
    protected $table = 'dbflow_workflow_action_executions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_instance_id',
        'workflow_task_id',
        'node_key',
        'action_key',
        'execution_mode',
        'status',
        'logical_execution_key',
        'visit_sequence',
        'attempts',
        'max_attempts',
        'queued_at',
        'next_attempt_at',
        'processing_started_at',
        'started_at',
        'succeeded_at',
        'failed_at',
        'exhausted_at',
        'cancelled_at',
        'skipped_at',
        'workflow_advanced_at',
        'last_error',
        'node_snapshot',
        'payload_snapshot',
        'result_metadata',
        'response_status',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'workflow_instance_id' => 'integer',
            'workflow_task_id' => 'integer',
            'execution_mode' => ActionExecutionMode::class,
            'status' => ActionExecutionStatus::class,
            'visit_sequence' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'queued_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'started_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
            'exhausted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'skipped_at' => 'datetime',
            'workflow_advanced_at' => 'datetime',
            'node_snapshot' => 'array',
            'payload_snapshot' => 'array',
            'result_metadata' => 'array',
            'response_status' => 'integer',
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
     * @return BelongsTo<WorkflowTask, $this>
     */
    public function workflowTask(): BelongsTo
    {
        return $this->belongsTo(WorkflowTask::class, 'workflow_task_id');
    }

    /**
     * @return HasMany<WorkflowActionAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(WorkflowActionAttempt::class, 'workflow_action_execution_id');
    }

    public function isClaimable(?\Illuminate\Support\Carbon $asOf = null): bool
    {
        $asOf ??= \Illuminate\Support\Carbon::now('UTC');

        if ($this->attempts >= $this->max_attempts) {
            return false;
        }

        if ($this->status !== ActionExecutionStatus::Queued) {
            return false;
        }

        if ($this->next_attempt_at !== null && $this->next_attempt_at->gt($asOf)) {
            return false;
        }

        return $this->cancelled_at === null;
    }

    public function isBlocking(): bool
    {
        return $this->execution_mode === ActionExecutionMode::ReliableBlocking;
    }
}
