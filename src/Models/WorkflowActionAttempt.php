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

class WorkflowActionAttempt extends Model
{
    protected $table = 'dbflow_workflow_action_attempts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_action_execution_id',
        'attempt_number',
        'status',
        'started_at',
        'completed_at',
        'last_error',
        'request_metadata',
        'response_metadata',
    ];

    protected function casts(): array
    {
        return [
            'workflow_action_execution_id' => 'integer',
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'request_metadata' => 'array',
            'response_metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WorkflowActionExecution, $this>
     */
    public function workflowActionExecution(): BelongsTo
    {
        return $this->belongsTo(WorkflowActionExecution::class, 'workflow_action_execution_id');
    }
}
