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

namespace DbflowLabs\Core\Providers;

use DbflowLabs\Core\Actions\LocalStatusUpdateHandler;
use DbflowLabs\Core\Actions\LogActionHandler;
use DbflowLabs\Core\Actions\ApproveTask;
use DbflowLabs\Core\Actions\CancelWorkflow;
use DbflowLabs\Core\Actions\RejectTask;
use DbflowLabs\Core\Actions\ReassignTask;
use DbflowLabs\Core\Actions\StartWorkflow;
use DbflowLabs\Core\Actions\SyncWorkflowDefinitions;
use DbflowLabs\Core\Console\Commands\SyncWorkflowDefinitionsCommand;
use DbflowLabs\Core\Console\Commands\ValidateWorkflowDefinitionsCommand;
use DbflowLabs\Core\Contracts\UserResolver;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\ExpressionEvaluator;
use DbflowLabs\Core\Services\TaskHooksRegistry;
use DbflowLabs\Core\Services\TransitionResolver;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionResolver;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Services\WorkflowNodeTraverser;
use DbflowLabs\Core\Support\ActionManager;
use DbflowLabs\Core\Support\ApprovalNodeAssigneeResolver;
use DbflowLabs\Core\Support\ConfigUserResolver;
use DbflowLabs\Core\Support\DbflowRuntime;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Support\ServiceProvider;

final class DBFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $existingDbflowConfig = $this->app->make('config')->get('dbflow');
        $preMergeEnabled = is_array($existingDbflowConfig) && array_key_exists('enabled', $existingDbflowConfig)
            ? $existingDbflowConfig['enabled']
            : null;

        $this->mergeConfigFrom(__DIR__.'/../../config/dbflow.php', 'dbflow');

        if ($preMergeEnabled !== null) {
            $this->app['config']->set('dbflow.enabled', $preMergeEnabled);
        }
    }

    public function boot(): void
    {
        $this->registerDefinitionManagementBindings();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncWorkflowDefinitionsCommand::class,
                ValidateWorkflowDefinitionsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../../config/dbflow.php' => config_path('dbflow.php'),
            ], 'dbflow-config');

            $this->publishes([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'dbflow-migrations');
        }

        if (! DbflowRuntime::isEnabled()) {
            return;
        }

        $this->registerRuntimeBindings();

        DBFlow::registerAll(
            $this->app->make(WorkflowDefinitionRegistry::class),
            $this->app->make(AssigneeResolverRegistry::class),
            $this->app->make(WorkflowHooksRegistry::class),
        );

        $this->registerCoreActionHandlers();
    }

    private function registerDefinitionManagementBindings(): void
    {
        if ($this->app->bound(WorkflowDefinitionRegistry::class)) {
            return;
        }

        $this->app->singleton(
            WorkflowDefinitionRegistry::class,
            static fn (): WorkflowDefinitionRegistry => new WorkflowDefinitionRegistry,
        );

        $this->app->singleton(
            AssigneeResolverRegistry::class,
            static fn (): AssigneeResolverRegistry => new AssigneeResolverRegistry,
        );

        $this->app->singleton(
            WorkflowHooksRegistry::class,
            fn ($app): WorkflowHooksRegistry => new WorkflowHooksRegistry($app),
        );

        $this->app->singleton(
            TaskHooksRegistry::class,
            fn ($app): TaskHooksRegistry => new TaskHooksRegistry($app),
        );

        $this->app->singleton(
            ExpressionEvaluator::class,
            static fn (): ExpressionEvaluator => new ExpressionEvaluator,
        );

        $this->app->singleton(
            WorkflowDefinitionValidator::class,
            static fn (): WorkflowDefinitionValidator => new WorkflowDefinitionValidator,
        );

        $this->app->singleton(
            SyncWorkflowDefinitions::class,
            fn ($app): SyncWorkflowDefinitions => new SyncWorkflowDefinitions(
                $app->make(WorkflowDefinitionRegistry::class),
                $app->make(WorkflowDefinitionValidator::class),
            ),
        );
    }

    private function registerRuntimeBindings(): void
    {
        if ($this->app->bound(StartWorkflow::class)) {
            return;
        }

        $this->app->singleton(UserResolver::class, function (): UserResolver {
            $resolverClass = config('dbflow.auth.resolver', ConfigUserResolver::class);

            if (is_string($resolverClass) && class_exists($resolverClass)) {
                return app($resolverClass);
            }

            return new ConfigUserResolver;
        });

        $this->app->singleton(
            TransitionResolver::class,
            fn ($app): TransitionResolver => new TransitionResolver($app->make(ExpressionEvaluator::class)),
        );

        $this->app->singleton(
            ActionManager::class,
            fn ($app): ActionManager => new ActionManager($app),
        );

        $this->app->singleton(
            ApprovalNodeAssigneeResolver::class,
            fn ($app): ApprovalNodeAssigneeResolver => new ApprovalNodeAssigneeResolver(
                $app->make(AssigneeResolverRegistry::class),
            ),
        );

        $this->app->singleton(
            WorkflowNodeTraverser::class,
            fn ($app): WorkflowNodeTraverser => new WorkflowNodeTraverser(
                $app->make(TransitionResolver::class),
                $app->make(ActionManager::class),
                $app->make(WorkflowLogger::class),
            ),
        );

        $this->app->singleton(
            StartWorkflow::class,
            fn ($app): StartWorkflow => new StartWorkflow(
                $app->make(WorkflowDefinitionResolver::class),
                $app->make(TransitionResolver::class),
                $app->make(WorkflowNodeTraverser::class),
                $app->make(ApprovalNodeAssigneeResolver::class),
                $app->make(WorkflowLogger::class),
                $app->make(WorkflowHooksRegistry::class),
                $app->make(TaskHooksRegistry::class),
            ),
        );

        $this->app->singleton(
            ApproveTask::class,
            fn ($app): ApproveTask => new ApproveTask(
                $app->make(TransitionResolver::class),
                $app->make(WorkflowNodeTraverser::class),
                $app->make(ApprovalNodeAssigneeResolver::class),
                $app->make(WorkflowLogger::class),
                $app->make(WorkflowHooksRegistry::class),
                $app->make(TaskHooksRegistry::class),
            ),
        );

        $this->app->singleton(
            RejectTask::class,
            fn ($app): RejectTask => new RejectTask(
                $app->make(TransitionResolver::class),
                $app->make(ApprovalNodeAssigneeResolver::class),
                $app->make(WorkflowLogger::class),
                $app->make(WorkflowHooksRegistry::class),
                $app->make(TaskHooksRegistry::class),
            ),
        );

        $this->app->singleton(
            CancelWorkflow::class,
            fn ($app): CancelWorkflow => new CancelWorkflow(
                $app->make(WorkflowLogger::class),
                $app->make(WorkflowHooksRegistry::class),
            ),
        );

        $this->app->singleton(
            ReassignTask::class,
            fn ($app): ReassignTask => new ReassignTask(
                $app->make(WorkflowLogger::class),
                $app->make(TaskHooksRegistry::class),
            ),
        );
    }

    private function registerCoreActionHandlers(): void
    {
        $actions = $this->app->make(ActionManager::class);

        $actions->register('log', LogActionHandler::class);
        $actions->register('local_status_update', LocalStatusUpdateHandler::class);
    }
}
