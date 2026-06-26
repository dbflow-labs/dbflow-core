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
use DbflowLabs\Core\Actions\StartWorkflow;
use DbflowLabs\Core\Contracts\UserResolver;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\TransitionResolver;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionResolver;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ActionManager;
use DbflowLabs\Core\Support\ApprovalNodeAssigneeResolver;
use DbflowLabs\Core\Support\ConfigUserResolver;
use Illuminate\Support\ServiceProvider;

final class DBFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/dbflow.php', 'dbflow');

        // Hosts may override the default user resolver via config('dbflow.auth.resolver')
        // to support UUID/ULID primary keys or custom authentication stacks.
        $this->app->singleton(UserResolver::class, function (): UserResolver {
            $resolverClass = config('dbflow.auth.resolver', ConfigUserResolver::class);

            if (is_string($resolverClass) && class_exists($resolverClass)) {
                return app($resolverClass);
            }

            return new ConfigUserResolver;
        });

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
            ActionManager::class,
            fn ($app): ActionManager => new ActionManager($app),
        );

        // ApprovalNodeAssigneeResolver depends on the global AssigneeResolverRegistry singleton.
        $this->app->singleton(
            ApprovalNodeAssigneeResolver::class,
            fn ($app): ApprovalNodeAssigneeResolver => new ApprovalNodeAssigneeResolver(
                $app->make(AssigneeResolverRegistry::class),
            ),
        );

        $this->app->singleton(
            StartWorkflow::class,
            fn ($app): StartWorkflow => new StartWorkflow(
                $app->make(WorkflowDefinitionResolver::class),
                $app->make(TransitionResolver::class),
                $app->make(ActionManager::class),
                $app->make(ApprovalNodeAssigneeResolver::class),
                $app->make(WorkflowLogger::class),
                $app->make(WorkflowHooksRegistry::class),
            ),
        );

        $this->app->singleton(
            ApproveTask::class,
            fn ($app): ApproveTask => new ApproveTask(
                $app->make(TransitionResolver::class),
                $app->make(ActionManager::class),
                $app->make(ApprovalNodeAssigneeResolver::class),
                $app->make(WorkflowLogger::class),
                $app->make(WorkflowHooksRegistry::class),
            ),
        );

        $this->app->singleton(
            RejectTask::class,
            fn ($app): RejectTask => new RejectTask(
                $app->make(TransitionResolver::class),
                $app->make(ApprovalNodeAssigneeResolver::class),
                $app->make(WorkflowLogger::class),
                $app->make(WorkflowHooksRegistry::class),
            ),
        );

        $this->app->singleton(
            CancelWorkflow::class,
            fn ($app): CancelWorkflow => new CancelWorkflow(
                $app->make(WorkflowLogger::class),
                $app->make(WorkflowHooksRegistry::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/dbflow.php' => config_path('dbflow.php'),
            ], 'dbflow-config');

            $this->publishes([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'dbflow-migrations');
        }

        DBFlow::registerAll(
            $this->app->make(WorkflowDefinitionRegistry::class),
            $this->app->make(AssigneeResolverRegistry::class),
            $this->app->make(WorkflowHooksRegistry::class),
        );

        $this->registerCoreActionHandlers();
    }

    private function registerCoreActionHandlers(): void
    {
        $actions = $this->app->make(ActionManager::class);

        $actions->register('log', LogActionHandler::class);
        $actions->register('local_status_update', LocalStatusUpdateHandler::class);
    }
}
