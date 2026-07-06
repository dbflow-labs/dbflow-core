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

namespace DbflowLabs\Core\Tests\Feature;

use DbflowLabs\Core\Contracts\WorkflowDefinitionProvider;
use DbflowLabs\Core\Contracts\WorkflowHooks;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\NullTaskHooks;
use DbflowLabs\Core\Services\TaskHooksRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Tests\Fixtures\SequentialTeamAssigneeResolver;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DbflowRegistrationApiTest extends TestCase
{
    #[Test]
    public function registration_methods_delegate_to_registries(): void
    {
        $definitions = $this->app->make(WorkflowDefinitionRegistry::class);
        $assignees = $this->app->make(AssigneeResolverRegistry::class);
        $workflowHooks = $this->app->make(WorkflowHooksRegistry::class);
        $taskHooks = $this->app->make(TaskHooksRegistry::class);

        DBFlow::registerCore($definitions, $assignees, $workflowHooks);
        DBFlow::registerAll($definitions, $assignees, $workflowHooks);

        DBFlow::registerDefinitionProvider($definitions, new RegistrationTestDefinitionProvider);

        DBFlow::registerAssigneeResolver(
            $assignees,
            'sequential_team',
            new SequentialTeamAssigneeResolver(['1', '2']),
        );

        DBFlow::registerWorkflowHooks($workflowHooks, 'registration_test', new RegistrationTestWorkflowHooks);
        DBFlow::registerTaskHooks($taskHooks, 'registration_test', NullTaskHooks::class);

        $this->assertNotNull($definitions->provider('registration_test'));
        $this->assertTrue($assignees->hasResolver('sequential_team'));
        $this->assertInstanceOf(RegistrationTestWorkflowHooks::class, $workflowHooks->resolve('registration_test'));
        $this->assertInstanceOf(NullTaskHooks::class, $taskHooks->resolve('registration_test'));
    }
}

/**
 * @internal
 */
final class RegistrationTestDefinitionProvider implements WorkflowDefinitionProvider
{
    public function key(): string
    {
        return 'registration_test';
    }

    public function definition(): array
    {
        return [
            'key' => 'registration_test',
            'name' => 'Registration Test',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['key' => 'end', 'type' => 'end', 'name' => 'End'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'end'],
            ],
        ];
    }
}

/**
 * @internal
 */
final class RegistrationTestWorkflowHooks implements WorkflowHooks
{
    public function onStarted(WorkflowInstance $instance): void {}

    public function onApproved(WorkflowInstance $instance): void {}

    public function onRejected(WorkflowInstance $instance): void {}

    public function onCancelled(WorkflowInstance $instance): void {}
}
