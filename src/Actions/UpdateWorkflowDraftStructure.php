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
use DbflowLabs\Core\Support\WorkflowBuilderNodeConfigNormalizer;

final class UpdateWorkflowDraftStructure
{
    public function __construct(
        private readonly SaveWorkflowDraft $saveWorkflowDraft,
        private readonly WorkflowBuilderNodeConfigNormalizer $nodeConfigNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public function handle(
        Workflow $workflow,
        array $definition,
        ?int $updatedBy = null,
    ): Workflow {
        $normalized = $this->normalize($definition, $workflow);

        return $this->saveWorkflowDraft->handle($workflow, $normalized, $updatedBy);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalize(array $definition, Workflow $workflow): array
    {
        $key = is_string($definition[WorkflowDefinitionSchema::FIELD_KEY] ?? null)
            && $definition[WorkflowDefinitionSchema::FIELD_KEY] !== ''
            ? $definition[WorkflowDefinitionSchema::FIELD_KEY]
            : (string) $workflow->key;

        $name = is_string($definition[WorkflowDefinitionSchema::FIELD_NAME] ?? null)
            && $definition[WorkflowDefinitionSchema::FIELD_NAME] !== ''
            ? $definition[WorkflowDefinitionSchema::FIELD_NAME]
            : (string) $workflow->name;

        $normalized = [
            WorkflowDefinitionSchema::FIELD_KEY => $key,
            WorkflowDefinitionSchema::FIELD_NAME => $name,
            WorkflowDefinitionSchema::FIELD_NODES => $this->normalizeNodes(
                is_array($definition[WorkflowDefinitionSchema::FIELD_NODES] ?? null)
                    ? $definition[WorkflowDefinitionSchema::FIELD_NODES]
                    : [],
            ),
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => $this->normalizeTransitions(
                is_array($definition[WorkflowDefinitionSchema::FIELD_TRANSITIONS] ?? null)
                    ? $definition[WorkflowDefinitionSchema::FIELD_TRANSITIONS]
                    : [],
            ),
        ];

        foreach ([
            WorkflowDefinitionSchema::FIELD_DESCRIPTION,
            WorkflowDefinitionSchema::FIELD_VERSION,
            WorkflowDefinitionSchema::FIELD_METADATA,
        ] as $field) {
            if (array_key_exists($field, $definition)) {
                $normalized[$field] = $definition[$field];
            }
        }

        return $normalized;
    }

    /**
     * @param  list<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private function normalizeNodes(array $nodes): array
    {
        $normalizedNodes = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $normalizedNode = $this->nodeConfigNormalizer->normalize($node);

            if ($normalizedNode !== []) {
                $normalizedNodes[] = $normalizedNode;
            }
        }

        return $normalizedNodes;
    }

    /**
     * @param  list<mixed>  $transitions
     * @return list<array<string, mixed>>
     */
    private function normalizeTransitions(array $transitions): array
    {
        $normalizedTransitions = [];

        foreach ($transitions as $transition) {
            if (! is_array($transition)) {
                continue;
            }

            $normalizedTransition = [];

            foreach ([
                WorkflowDefinitionSchema::FIELD_FROM,
                WorkflowDefinitionSchema::FIELD_TO,
            ] as $field) {
                if (isset($transition[$field]) && is_string($transition[$field]) && $transition[$field] !== '') {
                    $normalizedTransition[$field] = $transition[$field];
                }
            }

            if (array_key_exists(WorkflowDefinitionSchema::FIELD_CONDITION, $transition)) {
                $condition = $transition[WorkflowDefinitionSchema::FIELD_CONDITION];

                if (is_string($condition) && $condition !== '') {
                    $normalizedTransition[WorkflowDefinitionSchema::FIELD_CONDITION] = $condition;
                }
            }

            if (array_key_exists(WorkflowDefinitionSchema::FIELD_PRIORITY, $transition)) {
                $normalizedTransition[WorkflowDefinitionSchema::FIELD_PRIORITY] = $this->sanitizePriority(
                    $transition[WorkflowDefinitionSchema::FIELD_PRIORITY],
                );
            }

            if (array_key_exists(WorkflowDefinitionSchema::FIELD_IS_DEFAULT, $transition)) {
                $normalizedTransition[WorkflowDefinitionSchema::FIELD_IS_DEFAULT] = (bool) $transition[WorkflowDefinitionSchema::FIELD_IS_DEFAULT];
            }

            if ($normalizedTransition !== []) {
                $normalizedTransitions[] = $normalizedTransition;
            }
        }

        return $normalizedTransitions;
    }

    private function sanitizePriority(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return 0;
    }
}
