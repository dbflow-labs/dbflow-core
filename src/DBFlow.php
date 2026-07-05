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
use DbflowLabs\Core\Actions\RejectTask;
use DbflowLabs\Core\Actions\StartWorkflow;
use DbflowLabs\Core\Contracts\AssigneeResolver;
use DbflowLabs\Core\Contracts\WorkflowDefinitionProvider;
use DbflowLabs\Core\Contracts\WorkflowHooks;
use DbflowLabs\Core\Enums\RejectStrategy;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Database\Eloquent\Model;

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
}
