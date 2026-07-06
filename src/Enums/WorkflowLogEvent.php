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

namespace DbflowLabs\Core\Enums;

enum WorkflowLogEvent: string
{
    case WorkflowStarted = 'workflow_started';
    case WorkflowCompleted = 'workflow_completed';
    case WorkflowCancelled = 'workflow_cancelled';
    case WorkflowRejected = 'workflow_rejected';
    case TaskCreated = 'task_created';
    case TaskApproved = 'task_approved';
    case TaskRejected = 'task_rejected';
    case TaskCancelled = 'task_cancelled';
    case TaskSkipped = 'task_skipped';
    case TaskReassigned = 'task_reassigned';
    case ActionExecuted = 'action_executed';
    case ActionFailed = 'action_failed';

    public function label(): string
    {
        return match ($this) {
            self::WorkflowStarted => 'Workflow started',
            self::WorkflowCompleted => 'Workflow completed',
            self::WorkflowCancelled => 'Workflow cancelled',
            self::WorkflowRejected => 'Workflow rejected',
            self::TaskCreated => 'Task created',
            self::TaskApproved => 'Task approved',
            self::TaskRejected => 'Task rejected',
            self::TaskCancelled => 'Task cancelled',
            self::TaskSkipped => 'Task skipped',
            self::TaskReassigned => 'Task reassigned',
            self::ActionExecuted => 'Action node executed',
            self::ActionFailed => 'Action node execution failed',
        };
    }
}
