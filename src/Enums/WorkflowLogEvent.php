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
    case TaskTimedOut = 'task_timed_out';
    case TaskBecameOverdue = 'task_became_overdue';
    case TaskSlaReminderDispatched = 'task_sla_reminder_dispatched';
    case TaskSlaEscalated = 'task_sla_escalated';
    case TaskAssignedViaDelegation = 'task_assigned_via_delegation';
    case DelegationCreated = 'delegation_created';
    case DelegationRevoked = 'delegation_revoked';
    case PendingTasksMigrationCompleted = 'pending_tasks_migration_completed';
    case ActionExecuted = 'action_executed';
    case ActionFailed = 'action_failed';
    case ActionExecutionQueued = 'action_execution_queued';
    case ActionExecutionSucceeded = 'action_execution_succeeded';
    case ActionExecutionFailed = 'action_execution_failed';
    case ActionExecutionExhausted = 'action_execution_exhausted';
    case ActionExecutionSkipped = 'action_execution_skipped';
    case ActionExecutionManuallyRetried = 'action_execution_manually_retried';
    case ActionExecutionCancelled = 'action_execution_cancelled';
    case ActionExecutionRecovered = 'action_execution_recovered';

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
            self::TaskTimedOut => 'Task timed out',
            self::TaskBecameOverdue => 'Task became overdue',
            self::TaskSlaReminderDispatched => 'Task SLA reminder dispatched',
            self::TaskSlaEscalated => 'Task SLA escalated',
            self::TaskAssignedViaDelegation => 'Task assigned via delegation',
            self::DelegationCreated => 'Delegation created',
            self::DelegationRevoked => 'Delegation revoked',
            self::PendingTasksMigrationCompleted => 'Pending tasks migration completed',
            self::ActionExecuted => 'Action node executed',
            self::ActionFailed => 'Action node execution failed',
            self::ActionExecutionQueued => 'Action execution queued',
            self::ActionExecutionSucceeded => 'Action execution succeeded',
            self::ActionExecutionFailed => 'Action execution failed',
            self::ActionExecutionExhausted => 'Action execution exhausted retries',
            self::ActionExecutionSkipped => 'Action execution skipped',
            self::ActionExecutionManuallyRetried => 'Action execution manually retried',
            self::ActionExecutionCancelled => 'Action execution cancelled',
            self::ActionExecutionRecovered => 'Action execution recovered',
        };
    }
}
