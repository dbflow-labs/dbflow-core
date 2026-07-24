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

use DbflowLabs\Core\Enums\SlaEventStatus;
use DbflowLabs\Core\Enums\SlaEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowSlaEvent extends Model
{
    protected $table = 'dbflow_workflow_sla_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_task_id',
        'workflow_instance_id',
        'node_key',
        'event_type',
        'sequence',
        'scheduled_at',
        'status',
        'idempotency_key',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'processing_started_at',
        'processed_at',
        'failed_at',
        'cancelled_at',
        'last_error',
        'policy_snapshot',
        'result_metadata',
    ];

    protected function casts(): array
    {
        return [
            'workflow_task_id' => 'integer',
            'workflow_instance_id' => 'integer',
            'sequence' => 'integer',
            'scheduled_at' => 'datetime',
            'status' => SlaEventStatus::class,
            'event_type' => SlaEventType::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'policy_snapshot' => 'array',
            'result_metadata' => 'array',
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
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function isClaimable(): bool
    {
        if ($this->attempts >= $this->max_attempts) {
            return false;
        }

        return $this->status === SlaEventStatus::Pending
            || $this->status === SlaEventStatus::Failed;
    }
}

