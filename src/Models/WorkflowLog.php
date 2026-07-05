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

use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Support\DbflowAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowLog extends Model
{
    public $timestamps = false;

    protected $table = 'dbflow_workflow_logs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_instance_id',
        'workflow_task_id',
        'event',
        'actor_user_id',
        'comment',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'workflow_instance_id' => 'integer',
            'workflow_task_id' => 'integer',
            'event' => WorkflowLogEvent::class,
            'actor_user_id' => 'string',
            'payload' => 'array',
            'created_at' => 'datetime',
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
     * @return BelongsTo<Model, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(DbflowAuth::userModelClass(), 'actor_user_id');
    }
}
