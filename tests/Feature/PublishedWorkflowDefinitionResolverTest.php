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

use DbflowLabs\Core\Enums\WorkflowStatus;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Resolvers\PublishedWorkflowDefinitionResolver;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use PHPUnit\Framework\Attributes\Test;

final class PublishedWorkflowDefinitionResolverTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;

    #[Test]
    public function resolver_rejects_topology_defective_published_definition(): void
    {
        $this->seedTopologyDefectivePublishedWorkflow('isolated_runtime_flow');

        $resolver = new PublishedWorkflowDefinitionResolver(new WorkflowDefinitionValidator);

        $this->expectException(InvalidWorkflowDefinitionException::class);

        $resolver->resolve('isolated_runtime_flow');
    }

    #[Test]
    public function resolver_accepts_valid_published_definition(): void
    {
        $this->createMinimalPublishedWorkflow('valid_runtime_flow', 'Valid Runtime Flow');

        $resolver = new PublishedWorkflowDefinitionResolver(new WorkflowDefinitionValidator);

        $version = $resolver->resolve('valid_runtime_flow');

        $this->assertSame('valid_runtime_flow', $version->workflow->key);
        $this->assertNotEmpty($version->definition());
    }

    private function seedTopologyDefectivePublishedWorkflow(string $key): void
    {
        $workflow = Workflow::query()->create([
            'key' => $key,
            'name' => 'Isolated Runtime Flow',
            'is_enabled' => true,
            'status' => WorkflowStatus::Published,
        ]);

        $version = WorkflowVersion::query()->create([
            'workflow_id' => $workflow->getKey(),
            'version' => 1,
            'definition' => $this->topologyDefectiveDefinition($key),
            'is_active' => true,
            'published_at' => now(),
        ]);

        $workflow->forceFill(['current_version_id' => $version->getKey()])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function topologyDefectiveDefinition(string $key): array
    {
        return [
            'key' => $key,
            'name' => 'Topology Defective Flow',
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
                [
                    'key' => 'floating',
                    'type' => 'action',
                    'name' => 'Floating',
                    'config' => ['action_key' => 'noop'],
                ],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'end'],
            ],
        ];
    }
}
