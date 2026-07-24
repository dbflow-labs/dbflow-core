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

namespace DbflowLabs\Core\Tests\Feature\Contracts;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\Assignment\AssignmentProvenance;
use DbflowLabs\Core\Assignment\EffectiveAssignee;
use DbflowLabs\Core\Capabilities\RuntimeCapabilityRegistry;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\DefinitionSchemaVersion;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Enums\AssignmentSource;
use DbflowLabs\Core\Enums\RuntimeCapability;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use PHPUnit\Framework\Attributes\Test;

final class Stage11AContractTest extends TestCase
{
    use RegistersEngineTestResources;

    #[Test]
    public function missing_schema_version_normalizes_to_v10_and_remains_valid(): void
    {
        $definition = $this->basicDefinition();
        unset($definition['schema_version']);

        $blueprint = Blueprint::fromArray($definition);
        $this->assertSame(DefinitionSchemaVersion::V1_0, $blueprint->schemaVersion());

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function explicit_v11_schema_is_accepted_without_unsupported_runtime_fields(): void
    {
        $definition = $this->basicDefinition();
        $definition['schema_version'] = DefinitionSchemaVersion::V1_1;
        $definition['context_policy'] = ['data_source' => 'snapshot'];

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertTrue($result->isValid(), json_encode($result->errors()));
    }

    #[Test]
    public function unsupported_and_malformed_schema_versions_are_rejected(): void
    {
        $definition = $this->basicDefinition();
        $definition['schema_version'] = '2.0';

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid());
        $this->assertSame('unsupported_schema_version', $result->errors()[0]['code']);

        $definition['schema_version'] = 1.1;
        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function published_v10_snapshot_is_not_rewritten_on_reload(): void
    {
        $definition = $this->basicDefinition();
        unset($definition['schema_version']);

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        $version = app(PublishWorkflowDraft::class)->handle($workflow, 1);

        $stored = $version->definition();
        $this->assertArrayNotHasKey('schema_version', $stored);

        $reloaded = $version->fresh()->definition();
        $this->assertSame($stored, $reloaded);
    }

    #[Test]
    public function live_context_and_reliable_action_cannot_publish_without_capability(): void
    {
        $definition = $this->basicDefinition();
        $definition['schema_version'] = '1.1';
        $definition['context_policy'] = ['data_source' => 'live'];

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid());
        $this->assertSame('missing_runtime_capability', $result->errors()[0]['code']);

        $actionDefinition = $this->actionDefinition();
        $actionDefinition['nodes'][1]['config']['execution_mode'] = ActionExecutionMode::ReliableBlocking->value;

        app(RuntimeCapabilityRegistry::class)->disable(RuntimeCapability::ReliableAction);

        $result = app(WorkflowDefinitionValidator::class)->validate($actionDefinition);
        $this->assertFalse($result->isValid());
        $this->assertTrue(
            collect($result->errors())->contains(
                static fn (array $error): bool => $error['code'] === 'missing_runtime_capability',
            ),
        );
    }

    #[Test]
    public function delegation_sla_and_webhook_fields_cannot_publish(): void
    {
        $definition = $this->basicDefinition();
        $definition['delegation'] = ['enabled' => true];
        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid(), 'delegation');
        $this->assertSame('unsupported_definition_field', $result->errors()[0]['code']);

        foreach (['webhook' => RuntimeCapability::OutboundWebhook] as $field => $capability) {
            $definition = $this->basicDefinition();
            $definition[$field] = ['enabled' => true];

            app(RuntimeCapabilityRegistry::class)->disable($capability);

            $result = app(WorkflowDefinitionValidator::class)->validate($definition);
            $this->assertFalse($result->isValid(), $field);
            $this->assertSame('missing_runtime_capability', $result->errors()[0]['code']);
            $this->assertStringContainsString($capability->value, $result->errors()[0]['message']);
        }

        $registry = app(RuntimeCapabilityRegistry::class);
        $registry->disable(RuntimeCapability::Sla);

        $definition = $this->basicDefinition();
        $definition['sla'] = ['enabled' => true];
        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid(), 'sla');
        $this->assertSame('missing_runtime_capability', $result->errors()[0]['code']);
        $this->assertStringContainsString(RuntimeCapability::Sla->value, $result->errors()[0]['message']);
    }

    #[Test]
    public function legacy_action_defaults_to_legacy_sync_and_still_executes(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->actionDefinition();

        $node = Blueprint::fromArray($definition)->findNode('notify');
        $this->assertInstanceOf(ActionNode::class, $node);
        $this->assertSame(ActionExecutionMode::LegacySync, $node->executionMode());
        $this->assertSame(['action_key' => 'log', 'payload' => ['message' => 'ok']], $node->toArray()['config']);

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, (int) $users['first']->getKey());
        app(PublishWorkflowDraft::class)->handle($workflow, (int) $users['first']->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'S11A-ACTION']);
        $instance = DBFlow::start('stage11a_action', $subject, $users['first']->getKey());

        $this->assertSame('end', $instance->current_node_key);
    }

    #[Test]
    public function assignee_resolver_registry_is_wired_into_authoritative_validator(): void
    {
        $definition = $this->basicDefinition();
        $definition['nodes'][1]['config']['assignees'] = [
            'type' => 'permission',
            'value' => 'missing_team',
        ];

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid());
        $this->assertSame('missing_assignee_resolver', $result->errors()[0]['code']);

        app(AssigneeResolverRegistry::class)->register(
            'missing_team',
            new \DbflowLabs\Core\Tests\Fixtures\SequentialTeamAssigneeResolver([1]),
        );

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertTrue($result->isValid(), json_encode($result->errors()));
    }

    #[Test]
    public function invalid_action_config_values_are_rejected_with_field_paths(): void
    {
        $definition = $this->actionDefinition();
        $definition['nodes'][1]['config']['max_attempts'] = 'not-a-number';

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid());
        $this->assertSame('nodes.1.config.max_attempts', $result->errors()[0]['path']);
        $this->assertSame('invalid_value', $result->errors()[0]['code']);
    }

    #[Test]
    public function invalid_execution_mode_is_rejected_with_field_path(): void
    {
        $definition = $this->actionDefinition();
        $definition['nodes'][1]['config']['execution_mode'] = 'queued_magic';

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid());
        $this->assertSame('nodes.1.config.execution_mode', $result->errors()[0]['path']);
    }

    #[Test]
    public function non_array_context_policy_is_rejected(): void
    {
        $definition = $this->basicDefinition();
        $definition['context_policy'] = 'invalid';

        $result = app(WorkflowDefinitionValidator::class)->validate($definition);
        $this->assertFalse($result->isValid());
        $this->assertSame('context_policy', $result->errors()[0]['path']);
        $this->assertSame('invalid_value', $result->errors()[0]['code']);
    }

    #[Test]
    public function direct_validator_construction_accepts_snapshot_context_policy(): void
    {
        $definition = $this->basicDefinition();
        $definition['schema_version'] = '1.1';
        $definition['context_policy'] = ['data_source' => 'snapshot'];

        $result = (new WorkflowDefinitionValidator)->validate($definition);
        $this->assertTrue($result->isValid(), json_encode($result->errors()));
    }

    #[Test]
    public function action_node_constructor_keeps_legacy_position_argument_order(): void
    {
        $node = new ActionNode(
            'notify',
            'Notify',
            'log',
            ['message' => 'ok'],
            null,
            false,
            ['x' => 10, 'y' => 20],
            ['ui' => true],
        );

        $this->assertSame(ActionExecutionMode::LegacySync, $node->executionMode());
        $this->assertSame(['x' => 10, 'y' => 20], $node->position());
        $this->assertSame(['ui' => true], $node->metadata());
    }

    #[Test]
    public function assignment_provenance_contract_types_exist_without_changing_reassign_runtime(): void
    {
        $provenance = new AssignmentProvenance(
            source: AssignmentSource::Reassignment,
            originalAssigneeUserId: 1,
            effectiveAssigneeUserId: 2,
            reassignedFromAssignmentId: 10,
            reason: 'coverage',
        );

        $this->assertSame('reassignment', $provenance->toArray()['source']);
        $this->assertSame(WorkflowTaskAssignmentStatus::Reassigned->value, 'reassigned');

        $effective = new EffectiveAssignee(2, AssignmentSource::Reassignment, 1);
        $this->assertSame(1, $effective->originalUserId());

        $this->assertTrue(
            app(RuntimeCapabilityRegistry::class)->has(RuntimeCapability::Sla),
        );
        $this->assertTrue(
            app(RuntimeCapabilityRegistry::class)->has(RuntimeCapability::Delegation),
        );
    }

    #[Test]
    public function draft_non_strict_behavior_still_allows_invalid_draft_persistence(): void
    {
        $definition = $this->basicDefinition();
        unset($definition['nodes'][1]['config']['assignees']['value']);

        $result = app(WorkflowDefinitionValidator::class)->validate($definition, strict: false);
        $this->assertTrue($result->isValid());
        $this->assertNotEmpty($result->warnings());

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        $this->assertNotNull($workflow->draft_definition);

        $this->expectException(InvalidWorkflowDefinitionException::class);
        app(PublishWorkflowDraft::class)->handle($workflow, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function basicDefinition(string $key = 'stage11a_basic'): array
    {
        return [
            'key' => $key,
            'name' => 'Stage 1.1-A Basic',
            'schema_version' => '1.0',
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
                ['key' => 'end', 'type' => 'end', 'name' => 'End', 'config' => ['status' => 'approved']],
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
    private function actionDefinition(): array
    {
        return [
            'key' => 'stage11a_action',
            'name' => 'Stage 1.1-A Action',
            'schema_version' => '1.0',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'notify',
                    'type' => 'action',
                    'name' => 'Notify',
                    'config' => [
                        'action_key' => 'log',
                        'payload' => ['message' => 'ok'],
                    ],
                ],
                ['key' => 'end', 'type' => 'end', 'name' => 'End', 'config' => ['status' => 'approved']],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'notify'],
                ['from' => 'notify', 'to' => 'end'],
            ],
        ];
    }
}
