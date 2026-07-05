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

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Services\TransitionResolver;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Services\WorkflowNodeTraverser;
use DbflowLabs\Core\Support\ActionManager;
use DbflowLabs\Core\Tests\Fixtures\RecordingActionHandler;
use DbflowLabs\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

final class WorkflowNodeTraverserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingActionHandler::reset();
    }

    #[Test]
    public function traverse_executes_action_chain_and_reaches_end_node(): void
    {
        $actions = app(ActionManager::class);
        $actions->register('record', RecordingActionHandler::class);

        $instance = $this->createWorkflowInstance();

        $blueprint = Blueprint::fromArray([
            'key' => 'traverser_test',
            'name' => 'Traverser Test',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                ['key' => 'action_a', 'type' => 'action', 'name' => 'Action A', 'config' => ['action_key' => 'record']],
                ['key' => 'end', 'type' => 'end', 'name' => 'End'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'action_a', 'event' => 'start'],
                ['from' => 'action_a', 'to' => 'end', 'event' => 'approve'],
            ],
        ]);

        $traverser = app(WorkflowNodeTraverser::class);
        $actionNode = $blueprint->findNode('action_a');

        $this->assertNotNull($actionNode);

        $completed = false;
        $result = $traverser->traverse(
            $instance,
            $blueprint,
            $actionNode,
            [],
            null,
            static function (): void {},
            static function () use (&$completed): void {
                $completed = true;
            },
        );

        $this->assertSame('completed', $result);
        $this->assertTrue($completed);
        $this->assertSame(1, RecordingActionHandler::$callCount);
    }

    #[Test]
    public function traverse_throws_when_action_depth_exceeded(): void
    {
        $instance = $this->createWorkflowInstance();
        $traverser = app(WorkflowNodeTraverser::class);

        $nodes = [['key' => 'start', 'type' => 'start', 'name' => 'Start']];
        $transitions = [];

        for ($i = 1; $i <= WorkflowNodeTraverser::MAX_ACTION_DEPTH + 1; $i++) {
            $nodes[] = ['key' => "action_{$i}", 'type' => 'action', 'name' => "Action {$i}", 'config' => ['action_key' => '']];
            $from = $i === 1 ? 'start' : 'action_'.($i - 1);
            $transitions[] = ['from' => $from, 'to' => "action_{$i}", 'event' => $i === 1 ? 'start' : 'approve'];
        }

        $blueprint = Blueprint::fromArray([
            'key' => 'deep_chain',
            'name' => 'Deep Chain',
            'nodes' => $nodes,
            'transitions' => $transitions,
        ]);

        $firstAction = $blueprint->findNode('action_1');

        $this->assertNotNull($firstAction);

        $this->expectException(InvalidWorkflowDefinitionException::class);

        $traverser->traverse(
            $instance,
            $blueprint,
            $firstAction,
            [],
            null,
            static function (): void {},
            static function (): void {},
        );
    }

    private function createWorkflowInstance(): WorkflowInstance
    {
        $workflow = Workflow::query()->create([
            'key' => 'traverser_fixture',
            'name' => 'Traverser Fixture',
            'is_enabled' => true,
        ]);

        $version = WorkflowVersion::query()->create([
            'workflow_id' => $workflow->getKey(),
            'version' => 1,
            'definition' => ['key' => 'traverser_fixture', 'name' => 'Traverser Fixture', 'nodes' => [], 'transitions' => []],
            'is_active' => true,
            'published_at' => now(),
        ]);

        return WorkflowInstance::query()->create([
            'workflow_id' => $workflow->getKey(),
            'workflow_version_id' => $version->getKey(),
            'workflowable_type' => 'test_subject',
            'workflowable_id' => '1',
            'status' => WorkflowInstanceStatus::Running,
            'current_node_key' => 'start',
            'started_at' => now(),
        ]);
    }
}
