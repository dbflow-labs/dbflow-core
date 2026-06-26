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

use DbflowLabs\Core\Actions\SyncWorkflowDefinitions;
use DbflowLabs\Core\Contracts\WorkflowDefinitionProvider;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use PHPUnit\Framework\Attributes\Test;

final class SyncWorkflowDefinitionsTest extends TestCase
{
    #[Test]
    public function sync_rejects_definition_with_isolated_nodes(): void
    {
        $registry = new WorkflowDefinitionRegistry;
        $registry->register(new ArrayWorkflowDefinitionProvider(
            'sync_isolated_flow',
            $this->topologyDefectiveDefinition('sync_isolated_flow'),
        ));

        $sync = new SyncWorkflowDefinitions($registry, new WorkflowDefinitionValidator);

        $this->expectException(InvalidWorkflowDefinitionException::class);

        $sync->handle();
    }

    #[Test]
    public function sync_rejects_definition_with_invalid_transitions(): void
    {
        $definition = $this->minimalValidDefinition('sync_invalid_transition_flow');
        $definition['transitions'][] = ['from' => 'review', 'to' => 'missing_node'];

        $registry = new WorkflowDefinitionRegistry;
        $registry->register(new ArrayWorkflowDefinitionProvider('sync_invalid_transition_flow', $definition));

        $sync = new SyncWorkflowDefinitions($registry, new WorkflowDefinitionValidator);

        try {
            $sync->handle();
            $this->fail('Expected InvalidWorkflowDefinitionException was not thrown.');
        } catch (InvalidWorkflowDefinitionException $exception) {
            $this->assertStringContainsString('missing node', strtolower($exception->getMessage()));
        }
    }

    #[Test]
    public function sync_accepts_valid_simple_approval_definition(): void
    {
        $registry = new WorkflowDefinitionRegistry;
        $registry->register(new ArrayWorkflowDefinitionProvider(
            'sync_simple_approval',
            $this->minimalValidDefinition('sync_simple_approval'),
        ));

        $sync = new SyncWorkflowDefinitions($registry, new WorkflowDefinitionValidator);

        $summary = $sync->handle();

        $this->assertSame(['sync_simple_approval'], $summary['created']);
        $this->assertSame([], $summary['updated']);
        $this->assertSame([], $summary['unchanged']);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalValidDefinition(string $key): array
    {
        return [
            'key' => $key,
            'name' => 'Simple Approval',
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

    /**
     * @return array<string, mixed>
     */
    private function topologyDefectiveDefinition(string $key): array
    {
        $definition = $this->minimalValidDefinition($key);
        $definition['nodes'][] = [
            'key' => 'floating',
            'type' => 'action',
            'name' => 'Floating',
            'config' => ['action_key' => 'noop'],
        ];

        return $definition;
    }
}

/**
 * @internal
 */
final class ArrayWorkflowDefinitionProvider implements WorkflowDefinitionProvider
{
    /**
     * @param  array<string, mixed>  $definition
     */
    public function __construct(
        private readonly string $providerKey,
        private readonly array $definition,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function definition(): array
    {
        return $this->definition;
    }
}
