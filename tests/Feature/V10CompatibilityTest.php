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

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Tests\Fixtures\SequentialTeamAssigneeResolver;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Permanent v1.0 compatibility regression suite for Stage 1.1-0 fixtures.
 */
final class V10CompatibilityTest extends TestCase
{
    use RegistersEngineTestResources;

    /**
     * @return list<string>
     */
    public static function v10FixtureNames(): array
    {
        return [
            'basic_approval',
            'any_approval',
            'all_approval',
            'sequential_approval',
            'condition_routing',
            'rejection_return_flow',
            'action_node',
            'versioned_published_workflow',
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function v10FixtureProvider(): array
    {
        $cases = [];

        foreach (self::v10FixtureNames() as $fixtureName) {
            $cases[$fixtureName] = [$fixtureName];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('v10FixtureProvider')]
    public function fixture_parses_into_blueprint_and_passes_authoritative_validation(string $fixtureName): void
    {
        if (in_array($fixtureName, ['any_approval', 'all_approval', 'sequential_approval'], true)) {
            $this->registerV10TeamResolver([1, 2]);
        }

        $definition = $this->loadV10Fixture($fixtureName);

        $this->assertSame('1.0', $definition[WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION] ?? null);
        $this->assertIsString($definition[WorkflowDefinitionSchema::FIELD_KEY] ?? null);
        $this->assertIsArray($definition[WorkflowDefinitionSchema::FIELD_NODES] ?? null);
        $this->assertIsArray($definition[WorkflowDefinitionSchema::FIELD_TRANSITIONS] ?? null);

        $blueprint = Blueprint::fromArray($definition);
        $this->assertSame($definition[WorkflowDefinitionSchema::FIELD_KEY], $blueprint->key());
        $this->assertSame('1.0', $blueprint->schemaVersion());

        $result = app(WorkflowDefinitionValidator::class)->validate($definition, strict: true);
        $this->assertTrue(
            $result->isValid(),
            sprintf(
                'Fixture [%s] failed authoritative validation: %s',
                $fixtureName,
                json_encode($result->errors(), JSON_UNESCAPED_SLASHES),
            ),
        );
    }

    #[Test]
    #[DataProvider('v10FixtureProvider')]
    public function fixture_supports_publish_and_version_binding(string $fixtureName): void
    {
        if (in_array($fixtureName, ['any_approval', 'all_approval', 'sequential_approval'], true)) {
            $this->registerV10TeamResolver([1, 2]);
        }

        $definition = $this->loadV10Fixture($fixtureName);
        $workflow = app(CreateWorkflowDraft::class)->handle($definition, 1);
        $version = app(PublishWorkflowDraft::class)->handle($workflow, 1);
        $workflow = $workflow->fresh();

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertNotNull($workflow->current_version_id);
        $this->assertSame($version->getKey(), (int) $workflow->current_version_id);
        $this->assertTrue($version->is_active);
        $this->assertSame(
            $definition[WorkflowDefinitionSchema::FIELD_KEY],
            $version->definition()[WorkflowDefinitionSchema::FIELD_KEY] ?? null,
        );
    }

    #[Test]
    public function basic_approval_fixture_can_start_and_bind_instance_to_published_version(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadV10Fixture('basic_approval');
        $definition = $this->patchUserAssignee($definition, 'review', (string) $users['first']->getKey());

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, (int) $users['first']->getKey());
        $version = app(PublishWorkflowDraft::class)->handle($workflow, (int) $users['first']->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'V10-BASIC-001']);
        $instance = DBFlow::start('v10_basic_approval', $subject, $users['first']->getKey());

        $this->assertInstanceOf(WorkflowInstance::class, $instance);
        $this->assertSame(WorkflowInstanceStatus::Running, $instance->status);
        $this->assertSame($version->getKey(), (int) $instance->workflow_version_id);
        $this->assertSame($version->definition(), $instance->definition());

        $task = WorkflowTask::query()
            ->where('workflow_instance_id', $instance->getKey())
            ->where('node_key', 'review')
            ->where('status', WorkflowTaskStatus::Pending)
            ->first();

        $this->assertInstanceOf(WorkflowTask::class, $task);

        $completed = DBFlow::approve($task, $users['first']->getKey());
        $this->assertSame(WorkflowInstanceStatus::Approved, $completed->status);
    }

    #[Test]
    public function action_node_fixture_accepts_v10_action_semantics_and_executes_builtin_log_handler(): void
    {
        $users = $this->seedEngineUsers();
        $definition = $this->loadV10Fixture('action_node');

        $blueprint = Blueprint::fromArray($definition);
        $actionNode = $blueprint->findNode('notify');
        $this->assertInstanceOf(ActionNode::class, $actionNode);
        $this->assertSame('log', $actionNode->actionKey());
        $this->assertFalse($actionNode->stopOnError());

        $workflow = app(CreateWorkflowDraft::class)->handle($definition, (int) $users['first']->getKey());
        app(PublishWorkflowDraft::class)->handle($workflow, (int) $users['first']->getKey());

        $subject = ContextTestSubject::query()->create(['reference_code' => 'V10-ACTION-001']);
        $instance = DBFlow::start('v10_action_node', $subject, $users['first']->getKey());

        $this->assertSame(WorkflowInstanceStatus::Approved, $instance->status);
        $this->assertSame('end', $instance->current_node_key);
    }

    #[Test]
    public function condition_routing_fixture_preserves_supported_node_types(): void
    {
        $definition = $this->loadV10Fixture('condition_routing');
        $types = array_values(array_map(
            static fn (array $node): string => (string) ($node[WorkflowDefinitionSchema::FIELD_TYPE] ?? ''),
            array_filter(
                $definition[WorkflowDefinitionSchema::FIELD_NODES],
                static fn (mixed $node): bool => is_array($node),
            ),
        ));

        sort($types);

        $this->assertSame(
            ['approval', 'condition', 'end', 'end', 'start'],
            $types,
        );

        foreach (WorkflowDefinitionSchema::nodeTypes() as $supportedType) {
            $this->assertContains($supportedType, [...$types, 'action']);
        }

        $result = app(WorkflowDefinitionValidator::class)->validate($definition, strict: true);
        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function fixtures_do_not_introduce_v11_schema_fields(): void
    {
        $forbiddenKeys = [
            'schema_version_1_1',
            'delegation',
            'delegations',
            'sla',
            'webhook',
            'reassignment_policy',
            'context_contract',
            'field_catalog',
        ];

        foreach (self::v10FixtureNames() as $fixtureName) {
            $encoded = json_encode($this->loadV10Fixture($fixtureName), JSON_THROW_ON_ERROR);

            foreach ($forbiddenKeys as $forbiddenKey) {
                $this->assertStringNotContainsString(
                    '"'.$forbiddenKey.'"',
                    $encoded,
                    "Fixture [{$fixtureName}] must not introduce v1.1 field [{$forbiddenKey}].",
                );
            }

            $this->assertStringContainsString('"schema_version":"1.0"', str_replace(' ', '', $encoded));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadV10Fixture(string $fixtureName): array
    {
        $path = dirname(__DIR__).'/Fixtures/V10/'.$fixtureName.'.json';

        if (! is_file($path)) {
            throw new JsonException("V1.0 compatibility fixture [{$fixtureName}] was not found at [{$path}].");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new JsonException("V1.0 compatibility fixture [{$fixtureName}] could not be read.");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException("V1.0 compatibility fixture [{$fixtureName}] must decode to an array.");
        }

        return $decoded;
    }

    /**
     * @param  list<int|string>  $userIds
     */
    private function registerV10TeamResolver(array $userIds): void
    {
        app(AssigneeResolverRegistry::class)->register(
            'v10_team',
            new SequentialTeamAssigneeResolver($userIds),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function patchUserAssignee(array $definition, string $nodeKey, string $userId): array
    {
        foreach ($definition[WorkflowDefinitionSchema::FIELD_NODES] as $index => $node) {
            if (! is_array($node) || ($node[WorkflowDefinitionSchema::FIELD_KEY] ?? null) !== $nodeKey) {
                continue;
            }

            $definition[WorkflowDefinitionSchema::FIELD_NODES][$index][WorkflowDefinitionSchema::FIELD_CONFIG]['assignees'] = [
                'type' => 'user',
                'value' => $userId,
            ];
        }

        return $definition;
    }
}
