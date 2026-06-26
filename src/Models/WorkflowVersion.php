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

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowVersion extends Model
{
    protected $table = 'dbflow_workflow_versions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_id',
        'version',
        'definition',
        'is_active',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'workflow_id' => 'integer',
            'version' => 'integer',
            'definition' => 'array',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
            'published_by' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return is_array($this->definition) ? $this->definition : [];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    /**
     * @return HasMany<WorkflowInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'workflow_version_id');
    }
}
