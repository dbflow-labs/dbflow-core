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

use DbflowLabs\Core\Actions\StartWorkflow;
use DbflowLabs\Core\Actions\SyncWorkflowDefinitions;
use DbflowLabs\Core\Contracts\UserResolver;
use DbflowLabs\Core\Contracts\WorkflowDefinitionProvider;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Exceptions\WorkflowNotAvailableException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Support\DbflowRuntime;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DisabledDbflowTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('dbflow.enabled', false);
    }

    #[Test]
    public function disabled_config_keeps_definition_bindings_but_skips_runtime_bindings(): void
    {
        $this->assertFalse(DbflowRuntime::isEnabled());
        $this->assertInstanceOf(WorkflowDefinitionRegistry::class, $this->app->make(WorkflowDefinitionRegistry::class));
        $this->assertInstanceOf(AssigneeResolverRegistry::class, $this->app->make(AssigneeResolverRegistry::class));
        $this->assertInstanceOf(SyncWorkflowDefinitions::class, $this->app->make(SyncWorkflowDefinitions::class));
        $this->assertFalse($this->app->bound(UserResolver::class));
        $this->assertFalse($this->app->bound(StartWorkflow::class));
    }

    #[Test]
    public function runtime_start_throws_when_disabled(): void
    {
        $this->expectException(WorkflowNotAvailableException::class);

        DBFlow::start('disabled_flow', new ContextTestSubject, 1);
    }

    #[Test]
    public function runtime_approve_reject_and_cancel_throw_when_disabled(): void
    {
        $task = new WorkflowTask;
        $instance = new WorkflowInstance;

        try {
            DBFlow::approve($task);
            $this->fail('Expected WorkflowNotAvailableException for approve().');
        } catch (WorkflowNotAvailableException) {
        }

        try {
            DBFlow::reject($task);
            $this->fail('Expected WorkflowNotAvailableException for reject().');
        } catch (WorkflowNotAvailableException) {
        }

        try {
            DBFlow::cancel($instance);
            $this->fail('Expected WorkflowNotAvailableException for cancel().');
        } catch (WorkflowNotAvailableException) {
        }

        try {
            DBFlow::reassign($task, 1, '2');
            $this->fail('Expected WorkflowNotAvailableException for reassign().');
        } catch (WorkflowNotAvailableException) {
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function validate_command_remains_available_when_disabled(): void
    {
        DBFlow::registerDefinitionProvider(
            app(WorkflowDefinitionRegistry::class),
            new DisabledTestWorkflowDefinitionProvider,
        );

        $this->artisan('dbflow:validate', ['--source' => 'registry'])
            ->assertSuccessful();
    }

    #[Test]
    public function sync_command_remains_available_when_disabled(): void
    {
        DBFlow::registerDefinitionProvider(
            app(WorkflowDefinitionRegistry::class),
            new DisabledTestWorkflowDefinitionProvider,
        );

        $this->artisan('dbflow:sync')
            ->assertSuccessful();
    }
}

/**
 * @internal
 */
final class DisabledTestWorkflowDefinitionProvider implements WorkflowDefinitionProvider
{
    public function key(): string
    {
        return 'disabled_runtime_flow';
    }

    public function definition(): array
    {
        return [
            'key' => 'disabled_runtime_flow',
            'name' => 'Disabled Runtime Flow',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'review',
                    'type' => 'approval',
                    'name' => 'Review',
                    'config' => [
                        'approval_mode' => 'any',
                        'assignees' => ['type' => 'user', 'value' => '1'],
                    ],
                ],
                ['key' => 'end', 'type' => 'end', 'name' => 'End'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'end'],
            ],
        ];
    }
}
