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

use DbflowLabs\Core\Actions\LocalStatusUpdateHandler;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class LocalStatusUpdateHandlerTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;

    #[Test]
    public function execute_merges_metadata_updates_on_instance(): void
    {
        $this->createMinimalPublishedWorkflow('local_status_flow', 'Local Status Flow');
        $subject = ContextTestSubject::query()->create(['reference_code' => 'LOCAL-STATUS-001']);
        $instance = DBFlow::start('local_status_flow', $subject, '1', ['existing' => 'yes']);

        $node = ActionNode::fromArray([
            WorkflowDefinitionSchema::FIELD_KEY => 'status_update',
            WorkflowDefinitionSchema::FIELD_NAME => 'Status Update',
            WorkflowDefinitionSchema::FIELD_CONFIG => [
                'action_key' => 'local_status_update',
                'payload' => [
                    'metadata' => ['phase' => 'review'],
                    'metadata_key' => 'flag',
                    'metadata_value' => true,
                ],
            ],
        ]);

        app(LocalStatusUpdateHandler::class)->execute($node, $instance);

        $instance->refresh();

        $this->assertSame('yes', $instance->metadata['existing']);
        $this->assertSame('review', $instance->metadata['phase']);
        $this->assertTrue($instance->metadata['flag']);
    }

    #[Test]
    public function execute_is_noop_when_metadata_is_unchanged(): void
    {
        $this->createMinimalPublishedWorkflow('local_status_noop_flow', 'Local Status Noop Flow');
        $subject = ContextTestSubject::query()->create(['reference_code' => 'LOCAL-STATUS-002']);
        $instance = DBFlow::start('local_status_noop_flow', $subject, '1', ['stable' => 'value']);
        $updatedAt = $instance->updated_at;

        $node = new ActionNode('noop', 'Noop', 'local_status_update');

        app(LocalStatusUpdateHandler::class)->execute($node, $instance);

        $instance->refresh();

        $this->assertSame(['stable' => 'value'], $instance->metadata);
        $this->assertSame($updatedAt?->toJSON(), $instance->updated_at?->toJSON());
    }
}
