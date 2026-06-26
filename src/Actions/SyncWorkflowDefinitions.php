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

use DbflowLabs\Core\Enums\WorkflowStatus;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Support\Facades\DB;

final class SyncWorkflowDefinitions
{
    public function __construct(
        private readonly WorkflowDefinitionRegistry $registry,
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    /**
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>}
     */
    public function handle(): array
    {
        $summary = [
            'created' => [],
            'updated' => [],
            'unchanged' => [],
        ];

        DB::transaction(function () use (&$summary): void {
            foreach ($this->registry->providers() as $provider) {
                $providerKey = $provider->key();
                $definition = $provider->definition();

                if (($definition['key'] ?? null) !== $providerKey) {
                    throw new InvalidWorkflowDefinitionException(
                        'Workflow definition key ['.($definition['key'] ?? 'null')."] does not match provider key [{$providerKey}].",
                    );
                }

                $this->validator->validateOrFail($definition);

                $normalizedDefinition = $this->normalizeDefinition($definition);

                $workflow = Workflow::query()->where('key', $providerKey)->first();
                $wasCreated = $workflow === null;

                $workflowAttributes = [
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'is_enabled' => (bool) ($definition['enabled'] ?? true),
                ];

                if ($wasCreated) {
                    $workflow = Workflow::query()->create([
                        'key' => $providerKey,
                        ...$workflowAttributes,
                    ]);
                    $workflowMetadataChanged = false;
                } else {
                    $workflow->fill($workflowAttributes);
                    $workflowMetadataChanged = $workflow->isDirty();
                    $workflow->save();
                }

                $activeVersion = WorkflowVersion::query()
                    ->where('workflow_id', $workflow->getKey())
                    ->where('is_active', true)
                    ->first();

                $definitionChanged = $activeVersion === null
                    || ! $this->definitionsAreEquivalent($activeVersion->definition ?? [], $normalizedDefinition);

                if ($definitionChanged) {
                    WorkflowVersion::query()
                        ->where('workflow_id', $workflow->getKey())
                        ->where('is_active', true)
                        ->update(['is_active' => false]);

                    $maxVersion = (int) (WorkflowVersion::query()
                        ->where('workflow_id', $workflow->getKey())
                        ->max('version') ?? 0);

                    $newVersion = WorkflowVersion::query()->create([
                        'workflow_id' => $workflow->getKey(),
                        'version' => $maxVersion + 1,
                        'definition' => $normalizedDefinition,
                        'is_active' => true,
                        'published_at' => now(),
                    ]);

                    // NOTE: UI-owned workflows are managed by manual configuration; code sync does not overwrite their primary version pointer.
                    // New versions are stored as history only; current_version_id is not switched to the code version.
                    if ($workflow->source !== 'ui') {
                        $workflow->forceFill([
                            'current_version_id' => $newVersion->getKey(),
                            'status' => WorkflowStatus::Published,
                        ])->save();
                    }
                }

                if ($wasCreated) {
                    $summary['created'][] = $providerKey;
                } elseif ($definitionChanged || $workflowMetadataChanged) {
                    $summary['updated'][] = $providerKey;
                } else {
                    $summary['unchanged'][] = $providerKey;
                }
            }
        });

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalizeDefinition(array $definition): array
    {
        $normalized = [];

        foreach ($definition as $key => $value) {
            if ($key === 'nodes' && is_array($value)) {
                $normalized['nodes'] = $this->normalizeSequentialArray($value);

                continue;
            }

            if ($key === 'transitions' && is_array($value)) {
                $normalized['transitions'] = $this->normalizeSequentialArray($value);

                continue;
            }

            $normalized[$key] = is_array($value) ? $this->normalizeAssociativeArray($value) : $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<mixed>
     */
    private function normalizeSequentialArray(array $items): array
    {
        $normalized = [];

        foreach (array_values($items) as $item) {
            $normalized[] = is_array($item) ? $this->normalizeAssociativeArray($item) : $item;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function normalizeAssociativeArray(array $array): array
    {
        if (array_is_list($array)) {
            return $this->normalizeSequentialArray($array);
        }

        $normalized = [];

        foreach ($array as $key => $value) {
            $normalized[$key] = is_array($value) ? $this->normalizeAssociativeArray($value) : $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function definitionsAreEquivalent(array $left, array $right): bool
    {
        return json_encode($this->normalizeDefinition($left), JSON_THROW_ON_ERROR)
            === json_encode($this->normalizeDefinition($right), JSON_THROW_ON_ERROR);
    }
}
