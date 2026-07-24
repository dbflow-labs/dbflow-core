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

namespace DbflowLabs\Core;

use DbflowLabs\Core\Actions\ApproveTask;
use DbflowLabs\Core\Actions\CancelWorkflow;
use DbflowLabs\Core\Actions\Delegation\CreateDelegation;
use DbflowLabs\Core\Actions\Delegation\RevokeDelegation;
use DbflowLabs\Core\Actions\ReassignTask;
use DbflowLabs\Core\Actions\RejectTask;
use DbflowLabs\Core\Actions\StartWorkflow;
use DbflowLabs\Core\Contracts\AssigneeResolver;
use DbflowLabs\Core\Contracts\TaskHooks;
use DbflowLabs\Core\Contracts\WorkflowDefinitionProvider;
use DbflowLabs\Core\Contracts\WorkflowHooks;
use DbflowLabs\Core\Enums\RejectStrategy;
use DbflowLabs\Core\Models\WorkflowDelegation;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\MigratePendingTasksToDelegate;
use DbflowLabs\Core\Services\TaskHooksRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Database\Eloquent\Model;
use Carbon\CarbonInterface;

final class DBFlow
{
    // -----------------------------------------------------------------------
    // Registration API (invoked during service provider boot)
    // -----------------------------------------------------------------------

    public static function registerCore(
        WorkflowDefinitionRegistry $definitions,
        AssigneeResolverRegistry $assignees,
        ?WorkflowHooksRegistry $hooks = null,
    ): void {}

    public static function registerDefinitionProvider(
        WorkflowDefinitionRegistry $definitions,
        WorkflowDefinitionProvider $provider,
    ): void {
        $definitions->register($provider);
    }

    public static function registerAssigneeResolver(
        AssigneeResolverRegistry $assignees,
        string $key,
        AssigneeResolver $resolver,
    ): void {
        $assignees->register($key, $resolver);
    }

    public static function registerWorkflowHooks(
        WorkflowHooksRegistry $hooks,
        string $workflowKey,
        WorkflowHooks|string $workflowHooks,
    ): void {
        $hooks->register($workflowKey, $workflowHooks);
    }

    public static function registerTaskHooks(
        TaskHooksRegistry $hooks,
        string $workflowKey,
        TaskHooks|string $taskHooks,
    ): void {
        $hooks->register($workflowKey, $taskHooks);
    }

    public static function registerAll(
        WorkflowDefinitionRegistry $definitions,
        AssigneeResolverRegistry $assignees,
        ?WorkflowHooksRegistry $hooks = null,
    ): void {
        self::registerCore($definitions, $assignees, $hooks);
    }

    // -----------------------------------------------------------------------
    // Unified runtime entry points (adapters must use these; do not instantiate actions manually)
    // -----------------------------------------------------------------------

    /**
     * Start a workflow instance.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function start(
        string $workflowKey,
        Model $workflowable,
        mixed $startedBy = null,
        array $metadata = [],
    ): WorkflowInstance {
        DbflowRuntime::ensureEnabled();

        return app(StartWorkflow::class)->handle($workflowKey, $workflowable, $startedBy, $metadata);
    }

    /**
     * Approve a pending task.
     */
    public static function approve(
        WorkflowTask $task,
        mixed $actor = null,
        ?string $comment = null,
    ): WorkflowInstance {
        DbflowRuntime::ensureEnabled();

        return app(ApproveTask::class)->handle($task, $actor, $comment);
    }

    /**
     * Reject a pending task.
     *
     * @param  string|null  $targetNodeKey  Only used with RejectStrategy::SpecificNode
     */
    public static function reject(
        WorkflowTask $task,
        mixed $actor = null,
        ?string $comment = null,
        RejectStrategy $strategy = RejectStrategy::Starter,
        ?string $targetNodeKey = null,
    ): WorkflowInstance {
        DbflowRuntime::ensureEnabled();

        return app(RejectTask::class)->handle($task, $actor, $comment, $strategy, $targetNodeKey);
    }

    /**
     * Cancel a running workflow instance.
     */
    public static function cancel(
        WorkflowInstance $instance,
        mixed $actor = null,
        ?string $comment = null,
    ): WorkflowInstance {
        DbflowRuntime::ensureEnabled();

        return app(CancelWorkflow::class)->handle($instance, $actor, $comment);
    }

    /**
     * Reassign a pending task assignment from the current assignee to another user.
     */
    public static function reassign(
        WorkflowTask $task,
        mixed $fromActor,
        string $toUserId,
        ?string $comment = null,
        ?string $idempotencyKey = null,
        int|string|null $assignmentId = null,
    ): WorkflowInstance {
        DbflowRuntime::ensureEnabled();

        return app(ReassignTask::class)->handle(
            $task,
            $fromActor,
            $toUserId,
            $comment,
            $idempotencyKey,
            $assignmentId,
        );
    }

    /**
     * Create a time-bounded delegation rule (does not migrate existing pending tasks).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function createDelegation(
        mixed $delegator,
        mixed $delegate,
        CarbonInterface|string $startsAt,
        CarbonInterface|string $endsAt,
        mixed $createdBy = null,
        ?string $workflowKey = null,
        ?string $nodeKey = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): WorkflowDelegation {
        DbflowRuntime::ensureEnabled();

        return app(CreateDelegation::class)->handle(
            $delegator,
            $delegate,
            $startsAt,
            $endsAt,
            $createdBy,
            $workflowKey,
            $nodeKey,
            $reason,
            $metadata,
        );
    }

    public static function revokeDelegation(
        WorkflowDelegation $delegation,
        mixed $revokedBy = null,
        ?string $reason = null,
    ): WorkflowDelegation {
        DbflowRuntime::ensureEnabled();

        return app(RevokeDelegation::class)->handle($delegation, $revokedBy, $reason);
    }

    /**
     * Explicitly migrate matching pending tasks for a delegation via ReassignTask.
     *
     * @return array<string, mixed>
     */
    public static function migratePendingTasksToDelegate(
        WorkflowDelegation $delegation,
        mixed $actor = null,
        ?int $maxTasks = null,
        ?string $batchKey = null,
        bool $dryRun = false,
    ): array {
        DbflowRuntime::ensureEnabled();

        return app(MigratePendingTasksToDelegate::class)->handle(
            $delegation,
            $actor,
            $maxTasks,
            $batchKey,
            $dryRun,
        );
    }
}
