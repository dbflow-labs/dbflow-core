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

use DbflowLabs\Core\Enums\WorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workflow extends Model
{
    protected $table = 'dbflow_workflows';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'description',
        'model_type',
        'is_enabled',
        'source',
        'owner',
        'draft_definition',
        'draft_validation_errors',
        'draft_validation_warnings',
        'draft_updated_at',
        'draft_updated_by',
        'current_version_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'draft_definition' => 'array',
            'draft_validation_errors' => 'array',
            'draft_validation_warnings' => 'array',
            'draft_updated_at' => 'datetime',
            'draft_updated_by' => 'integer',
            'current_version_id' => 'integer',
            'status' => WorkflowStatus::class,
        ];
    }

    public function lifecycleStatus(): WorkflowStatus
    {
        if ($this->status instanceof WorkflowStatus) {
            return $this->status;
        }

        if (is_string($this->status) && $this->status !== '') {
            $status = WorkflowStatus::tryFrom($this->status);

            if ($status !== null) {
                return $status;
            }
        }

        if ($this->hasPublishedVersion()) {
            return WorkflowStatus::Published;
        }

        return WorkflowStatus::Draft;
    }

    public function isDraft(): bool
    {
        return $this->lifecycleStatus() === WorkflowStatus::Draft;
    }

    public function isPublished(): bool
    {
        return $this->lifecycleStatus() === WorkflowStatus::Published;
    }

    public function isDisabled(): bool
    {
        return $this->lifecycleStatus() === WorkflowStatus::Disabled;
    }

    public function isArchived(): bool
    {
        return $this->lifecycleStatus() === WorkflowStatus::Archived;
    }

    public function canStartNewInstances(): bool
    {
        return $this->isPublished()
            && $this->hasPublishedVersion()
            && $this->is_enabled;
    }

    public function canBePublished(): bool
    {
        return ! $this->isArchived() && $this->hasDraft();
    }

    public function canBeDisabled(): bool
    {
        return ! $this->isArchived()
            && ($this->isPublished() || $this->isDraft());
    }

    public function canBeEnabled(): bool
    {
        return $this->isDisabled();
    }

    public function canBeArchived(): bool
    {
        return ! $this->isArchived();
    }

    public function canBeDeleted(): bool
    {
        return ! $this->instances()->exists();
    }

    public function hasDraft(): bool
    {
        if (! is_array($this->draft_definition)) {
            return false;
        }

        return $this->draft_definition !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function draftDefinition(): array
    {
        return is_array($this->draft_definition) ? $this->draft_definition : [];
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function draftValidationErrors(): array
    {
        return is_array($this->draft_validation_errors) ? $this->draft_validation_errors : [];
    }

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function draftValidationWarnings(): array
    {
        return is_array($this->draft_validation_warnings) ? $this->draft_validation_warnings : [];
    }

    public function draftIsValid(): bool
    {
        return $this->hasDraft() && $this->draftValidationErrors() === [];
    }

    public function hasPublishedVersion(): bool
    {
        if ($this->current_version_id !== null) {
            return true;
        }

        return $this->versions()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function currentDefinition(): array
    {
        $currentVersion = $this->relationLoaded('currentVersion')
            ? $this->currentVersion
            : $this->currentVersion()->first();

        if ($currentVersion === null) {
            return [];
        }

        return $currentVersion->definition();
    }

    /**
     * @return HasMany<WorkflowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class, 'workflow_id');
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'current_version_id');
    }

    /**
     * @return HasOne<WorkflowVersion, $this>
     */
    public function activeVersion(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class, 'workflow_id')->where('is_active', true);
    }

    /**
     * @return HasMany<WorkflowInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'workflow_id');
    }
}
