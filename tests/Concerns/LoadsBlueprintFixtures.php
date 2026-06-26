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

namespace DbflowLabs\Core\Tests\Concerns;

use DbflowLabs\Core\Actions\CreateWorkflowDraft;
use DbflowLabs\Core\Actions\PublishWorkflowDraft;
use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Models\Workflow;
use JsonException;

trait LoadsBlueprintFixtures
{
    /**
     * @return array<string, mixed>
     */
    protected function loadBlueprintFixture(string $fixtureName): array
    {
        $path = __DIR__.'/../Fixtures/blueprints/'.$fixtureName.'.json';

        if (! is_file($path)) {
            throw new JsonException("Blueprint fixture [{$fixtureName}] was not found at [{$path}].");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new JsonException("Blueprint fixture [{$fixtureName}] could not be read.");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException("Blueprint fixture [{$fixtureName}] must decode to an array.");
        }

        return $decoded;
    }

    protected function blueprintFromFixture(string $fixtureName): Blueprint
    {
        return Blueprint::fromArray($this->loadBlueprintFixture($fixtureName));
    }

    protected function publishBlueprintFixture(string $fixtureName, ?int $actorUserId = 1): Workflow
    {
        $workflow = app(CreateWorkflowDraft::class)->handle(
            $this->loadBlueprintFixture($fixtureName),
            $actorUserId,
        );

        app(PublishWorkflowDraft::class)->handle($workflow, $actorUserId);

        return $workflow->fresh();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    protected function enrichDefinitionWithCanvasMetadata(array $definition): array
    {
        $enriched = $definition;
        $enriched[WorkflowDefinitionSchema::FIELD_METADATA] = [
            'canvas' => 'dbflow-filament-pro',
            'zoom' => 1.75,
            'viewport' => ['x' => -120, 'y' => 48],
        ];

        if (! is_array($enriched[WorkflowDefinitionSchema::FIELD_NODES] ?? null)) {
            return $enriched;
        }

        $offset = 0;

        foreach ($enriched[WorkflowDefinitionSchema::FIELD_NODES] as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $offset += 40;
            $enriched[WorkflowDefinitionSchema::FIELD_NODES][$index][WorkflowDefinitionSchema::FIELD_METADATA] = [
                'x' => 100 + $offset,
                'y' => 200 + $offset,
                'lines' => ['edge-'.$index.'-decoy'],
                'shape' => 'rect',
            ];
            $enriched[WorkflowDefinitionSchema::FIELD_NODES][$index][WorkflowDefinitionSchema::FIELD_POSITION] = [
                'x' => 999 + $offset,
                'y' => 888 + $offset,
            ];
        }

        return $enriched;
    }
}
