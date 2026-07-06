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

namespace DbflowLabs\Core\Actions;

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Models\Workflow;

/**
 * @internal Definition-management action; not part of the stable 1.0 public API.
 */
final class UpdateWorkflowDraftNodePositions
{
    public function __construct(
        private readonly SaveWorkflowDraft $saveWorkflowDraft,
    ) {}

    /**
     * @param  array<string, array{x?: mixed, y?: mixed}>  $positions
     */
    public function handle(
        Workflow $workflow,
        array $positions,
        int|string|null $updatedBy = null,
    ): Workflow {
        $definition = $this->resolveSeedDefinition($workflow);
        $definition = $this->applyPositions($definition, $positions);

        return $this->saveWorkflowDraft->handle($workflow, $definition, $updatedBy);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSeedDefinition(Workflow $workflow): array
    {
        if ($workflow->hasDraft()) {
            return $workflow->draftDefinition();
        }

        $workflow->loadMissing('currentVersion');

        $currentDefinition = $workflow->currentDefinition();

        if ($currentDefinition !== []) {
            return $currentDefinition;
        }

        return WorkflowDefinitionSchema::emptyDefinition();
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, array{x?: mixed, y?: mixed}>  $positions
     * @return array<string, mixed>
     */
    private function applyPositions(array $definition, array $positions): array
    {
        $definition = json_decode(json_encode($definition, JSON_THROW_ON_ERROR), true);

        if (! is_array($definition)) {
            return WorkflowDefinitionSchema::emptyDefinition();
        }

        $nodes = $definition['nodes'] ?? null;

        if (! is_array($nodes)) {
            return $definition;
        }

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $key = $node['key'] ?? null;

            if (! is_string($key) || $key === '' || ! array_key_exists($key, $positions)) {
                continue;
            }

            $position = $positions[$key];

            if (! is_array($position)) {
                continue;
            }

            $definition['nodes'][$index]['position'] = [
                'x' => $this->sanitizeCoordinate($position['x'] ?? 0),
                'y' => $this->sanitizeCoordinate($position['y'] ?? 0),
            ];
        }

        return $definition;
    }

    private function sanitizeCoordinate(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, min(10000, $value));
        }

        if (is_float($value)) {
            return max(0, min(10000, (int) round($value)));
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, min(10000, (int) round((float) $value)));
        }

        return 0;
    }
}
