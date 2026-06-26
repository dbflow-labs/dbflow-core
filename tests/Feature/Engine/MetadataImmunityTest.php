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

namespace DbflowLabs\Core\Tests\Feature\Engine;

use DbflowLabs\Core\DBFlow;
use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Services\TransitionResolver;
use DbflowLabs\Core\Tests\Concerns\LoadsBlueprintFixtures;
use DbflowLabs\Core\Tests\Concerns\RegistersEngineTestResources;
use DbflowLabs\Core\Tests\Fixtures\ContextTestSubject;
use DbflowLabs\Core\Validation\BlueprintValidator;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MetadataImmunityTest extends TestCase
{
    use LoadsBlueprintFixtures;
    use RegistersEngineTestResources;

    #[Test]
    public function canvas_metadata_does_not_change_validation_outcome(): void
    {
        $baseline = $this->loadBlueprintFixture('conditional_routing');
        $decorated = $this->enrichDefinitionWithCanvasMetadata($baseline);

        $validator = new BlueprintValidator;

        $baselineResult = $validator->validateArray($baseline);
        $decoratedResult = $validator->validateArray($decorated);

        $this->assertTrue($baselineResult->isValid());
        $this->assertTrue($decoratedResult->isValid());
        $this->assertSame($baselineResult->errors(), $decoratedResult->errors());
        $this->assertSame($baselineResult->warnings(), $decoratedResult->warnings());
    }

    #[Test]
    public function canvas_metadata_does_not_change_transition_resolution(): void
    {
        $baselineBlueprint = Blueprint::fromArray($this->loadBlueprintFixture('conditional_routing'));
        $decoratedBlueprint = Blueprint::fromArray(
            $this->enrichDefinitionWithCanvasMetadata($this->loadBlueprintFixture('conditional_routing')),
        );

        $resolver = new TransitionResolver;
        $variables = ['amount' => 15000];

        $baselineNext = $resolver->nextNode($baselineBlueprint, 'amount_gate', variables: $variables);
        $decoratedNext = $resolver->nextNode($decoratedBlueprint, 'amount_gate', variables: $variables);

        $this->assertNotNull($baselineNext);
        $this->assertNotNull($decoratedNext);
        $this->assertSame($baselineNext->key(), $decoratedNext->key());
        $this->assertSame('high_value_review', $baselineNext->key());
    }

    #[Test]
    public function canvas_metadata_does_not_change_runtime_execution_outcome(): void
    {
        $users = $this->seedEngineUsers();

        $baselineDefinition = $this->loadBlueprintFixture('conditional_routing');
        $baselineDefinition = $this->patchAssigneeUserId($baselineDefinition, 'high_value_review', (int) $users['first']->getKey());

        $decoratedDefinition = $this->enrichDefinitionWithCanvasMetadata($baselineDefinition);
        $decoratedDefinition['key'] = 'conditional_routing_decorated';
        $decoratedDefinition['name'] = 'Conditional Routing Decorated';

        $this->publishDefinition($baselineDefinition);
        $this->publishDefinition($decoratedDefinition);

        $baselineSubject = ContextTestSubject::query()
            ->create(['reference_code' => 'META-BASE'])
            ->withWorkflowVariables(['amount' => 2500]);

        $decoratedSubject = ContextTestSubject::query()
            ->create(['reference_code' => 'META-DECO'])
            ->withWorkflowVariables(['amount' => 2500]);

        $baselineInstance = DBFlow::start('conditional_routing', $baselineSubject, $users['first']->getKey());
        $decoratedInstance = DBFlow::start('conditional_routing_decorated', $decoratedSubject, $users['first']->getKey());

        $this->assertSame($baselineInstance->status, $decoratedInstance->status);
        $this->assertSame($baselineInstance->current_node_key, $decoratedInstance->current_node_key);
        $this->assertSame(
            WorkflowTask::query()->where('workflow_instance_id', $baselineInstance->getKey())->count(),
            WorkflowTask::query()->where('workflow_instance_id', $decoratedInstance->getKey())->count(),
        );
    }

    #[Test]
    public function mutating_node_metadata_after_hydration_does_not_affect_blueprint_topology_queries(): void
    {
        $blueprint = $this->blueprintFromFixture('action_automation');
        $outgoingBefore = $blueprint->transitionsFrom('route');

        $node = $blueprint->findNode('route');
        $this->assertNotNull($node);
        $node->setMetadata(['x' => 42, 'lines' => ['fake-edge']]);

        $outgoingAfter = $blueprint->transitionsFrom('route');

        $this->assertCount(count($outgoingBefore), $outgoingAfter);
        $this->assertSame(
            array_map(static fn ($transition) => $transition->to(), $outgoingBefore),
            array_map(static fn ($transition) => $transition->to(), $outgoingAfter),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function publishDefinition(array $definition): void
    {
        $workflow = app(\DbflowLabs\Core\Actions\CreateWorkflowDraft::class)->handle($definition, 1);
        app(\DbflowLabs\Core\Actions\PublishWorkflowDraft::class)->handle($workflow, 1);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function patchAssigneeUserId(array $definition, string $nodeKey, int $userId): array
    {
        foreach ($definition['nodes'] as $index => $node) {
            if (! is_array($node) || ($node['key'] ?? null) !== $nodeKey) {
                continue;
            }

            $definition['nodes'][$index]['config']['assignees']['value'] = (string) $userId;
        }

        return $definition;
    }
}
