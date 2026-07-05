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

namespace DbflowLabs\Core\Services;

use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Generic pending-task query service for dashboards, APIs, mail digests, and similar consumers.
 *
 * Differences from Filament-specific MyWorkflowTasksQuery:
 * - Returns a LengthAwarePaginator and is UI-framework agnostic
 * - Eager-loads workflowInstance.workflowVersion for canvas presenters
 * - Does not depend on the Auth facade; caller supplies userId explicitly for testability
 */
final class WorkflowTaskQueryService
{
    /**
     * Returns a paginated list of pending workflow task assignments for the given user.
     *
     * Executes only two SQL queries (assignments plus eager loads).
     * Each record includes the full workflowTask -> workflowInstance -> workflow chain,
     * allowing O(1) access to deep relations without extra queries.
     *
     * @param  int  $perPage  Items per page; default 10, recommended maximum 50
     * @return LengthAwarePaginator<int, WorkflowTaskAssignment>
     */
    public function getPendingTasksForUser(int $userId, int $perPage = 10): LengthAwarePaginator
    {
        return WorkflowTaskAssignment::query()
            ->where('assignee_user_id', $userId)
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->whereHas('workflowTask', static function ($query): void {
                // Only return rows where the parent task is still pending,
                // avoiding stale assignments after multi-approver completion
                $query->where('status', WorkflowTaskStatus::Pending);
            })
            ->with([
                'workflowTask',
                'workflowTask.workflowInstance',
                'workflowTask.workflowInstance.workflow',
                'workflowTask.workflowInstance.workflowVersion',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Returns the pending task count for badges and lightweight notifications.
     *
     * Executes a single COUNT query without eager loading.
     */
    public function countPendingTasksForUser(int $userId): int
    {
        return WorkflowTaskAssignment::query()
            ->where('assignee_user_id', $userId)
            ->where('status', WorkflowTaskAssignmentStatus::Pending)
            ->whereHas('workflowTask', static function ($query): void {
                $query->where('status', WorkflowTaskStatus::Pending);
            })
            ->count();
    }
}
