<?php

declare(strict_types=1);

namespace DbflowLabs\Core\Tests\Feature;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Services\WorkflowDefinitionResolver;
use DbflowLabs\Core\Support\WorkflowBuilderNodeFactory;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class WorkflowDefinitionResolverContainerTest extends TestCase
{
    #[Test]
    public function container_resolver_accepts_published_outbound_webhook_definition(): void
    {
        $factory = app(WorkflowBuilderNodeFactory::class);

        $definition = [
            WorkflowDefinitionSchema::FIELD_KEY => 'container_webhook_flow',
            WorkflowDefinitionSchema::FIELD_NAME => 'Container Webhook Flow',
            WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION => '1.1',
            WorkflowDefinitionSchema::FIELD_NODES => [
                $factory->make(WorkflowDefinitionSchema::NODE_TYPE_START, 'start', 'Start'),
                [
                    'key' => 'notify_partner',
                    'type' => WorkflowDefinitionSchema::NODE_TYPE_ACTION,
                    'name' => 'Notify Partner',
                    'config' => [
                        'action_key' => 'outbound_webhook',
                        'execution_mode' => ActionExecutionMode::ReliableNonBlocking->value,
                        'payload' => [
                            'url' => 'https://hooks.example.test/demo',
                            'method' => 'POST',
                            'body' => '{"instance_id":"{{ workflow.instance_id }}"}',
                            'signing_secret_key' => '{{ secret.webhook_signing }}',
                        ],
                    ],
                ],
                $factory->make(WorkflowDefinitionSchema::NODE_TYPE_END, 'end', 'End'),
            ],
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => [
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'start',
                    WorkflowDefinitionSchema::FIELD_TO => 'notify_partner',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
                [
                    WorkflowDefinitionSchema::FIELD_FROM => 'notify_partner',
                    WorkflowDefinitionSchema::FIELD_TO => 'end',
                    WorkflowDefinitionSchema::FIELD_IS_DEFAULT => true,
                ],
            ],
        ];

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $version = app(WorkflowDefinitionResolver::class)->activeVersion('container_webhook_flow');

        $this->assertSame('container_webhook_flow', $version->workflow->key);
        $this->assertContains('action', collect($version->definition()['nodes'] ?? [])->pluck('type')->all());
    }
}
