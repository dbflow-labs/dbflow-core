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

use DbflowLabs\Core\Actions\LogActionHandler;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Models\WorkflowLog;
use DbflowLabs\Core\Tests\Concerns\BuildsMinimalPublishedWorkflow;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class LogActionHandlerTest extends TestCase
{
    use BuildsMinimalPublishedWorkflow;

    #[Test]
    public function execute_persists_configured_action_log_entry(): void
    {
        $this->createMinimalPublishedWorkflow('log_handler_flow', 'Log Handler Flow');
        $subject = ContextTestSubject::query()->create(['reference_code' => 'LOG-HANDLER-001']);
        $instance = DBFlow::start('log_handler_flow', $subject, '1');

        $node = ActionNode::fromArray([
            WorkflowDefinitionSchema::FIELD_KEY => 'audit',
            WorkflowDefinitionSchema::FIELD_NAME => 'Audit Step',
            WorkflowDefinitionSchema::FIELD_CONFIG => [
                'action_key' => 'log',
                'payload' => [
                    'message' => 'Audit checkpoint reached.',
                    'event' => 'action_executed',
                    'context' => ['step' => 'audit'],
                ],
            ],
        ]);

        app(LogActionHandler::class)->execute($node, $instance);

        $log = WorkflowLog::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('event', 'action_executed')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Audit checkpoint reached.', $log->comment);
        $this->assertSame('audit', $log->payload['node_key']);
        $this->assertSame('log', $log->payload['action_key']);
        $this->assertSame('audit', $log->payload['step']);
    }

    #[Test]
    public function execute_uses_defaults_when_payload_is_missing(): void
    {
        $this->createMinimalPublishedWorkflow('log_defaults_flow', 'Log Defaults Flow');
        $subject = ContextTestSubject::query()->create(['reference_code' => 'LOG-DEFAULTS-001']);
        $instance = DBFlow::start('log_defaults_flow', $subject, '1');

        $node = ActionNode::fromArray([
            WorkflowDefinitionSchema::FIELD_KEY => 'notify',
            WorkflowDefinitionSchema::FIELD_NAME => 'Notify Step',
            WorkflowDefinitionSchema::FIELD_CONFIG => [
                'action_key' => 'log',
                'payload' => [
                    'event' => 'action_executed',
                ],
            ],
        ]);

        app(LogActionHandler::class)->execute($node, $instance);

        $this->assertTrue(
            WorkflowLog::query()
                ->where('workflow_instance_id', $instance->getKey())
                ->where('event', 'action_executed')
                ->where('comment', 'Notify Step')
                ->exists(),
        );
    }
}
