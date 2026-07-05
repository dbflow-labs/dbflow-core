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

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Support\DbflowAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkflowInstance extends Model
{
    protected $table = 'dbflow_workflow_instances';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_id',
        'workflow_version_id',
        'workflowable_type',
        'workflowable_id',
        'business_key',
        'active_key',
        'status',
        'current_node_key',
        'started_by_user_id',
        'started_at',
        'completed_at',
        'cancelled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'workflow_id' => 'integer',
            'workflow_version_id' => 'integer',
            'started_by_user_id' => 'string',
            'status' => WorkflowInstanceStatus::class,
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function workflowable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(DbflowAuth::userModelClass(), 'started_by_user_id');
    }

    /**
     * @return HasMany<WorkflowTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, 'workflow_instance_id');
    }

    /**
     * @return HasMany<WorkflowLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class, 'workflow_instance_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $this->loadMissing('workflowVersion');

        return $this->workflowVersion?->definition() ?? [];
    }

    public function blueprint(): Blueprint
    {
        return Blueprint::fromArray($this->definition());
    }
}
