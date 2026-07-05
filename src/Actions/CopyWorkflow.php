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
use DbflowLabs\Core\Enums\WorkflowStatus;
use DbflowLabs\Core\Exceptions\WorkflowInvalidStateException;
use DbflowLabs\Core\Exceptions\WorkflowKeyAlreadyExistsException;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Support\Facades\DB;

final class CopyWorkflow
{
    public function __construct(
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    public function handle(
        Workflow $source,
        string $newKey,
        string $newName,
        int|string|null $createdBy = null,
    ): Workflow {
        $this->assertValidNewKey($newKey);

        if ($newName === '') {
            throw new WorkflowInvalidStateException('Workflow name is required.');
        }

        $definition = $this->resolveSourceDefinition($source);
        $definition['key'] = $newKey;
        $definition['name'] = $newName;

        $validationResult = $this->validator->validate($definition);

        return DB::transaction(function () use ($newKey, $newName, $source, $definition, $validationResult, $createdBy): Workflow {
            $attributes = [
                'key' => $newKey,
                'name' => $newName,
                'description' => $source->description,
                'is_enabled' => true,
                'status' => WorkflowStatus::Draft->value,
                'draft_definition' => $definition,
                'draft_validation_errors' => $validationResult->isValid()
                    ? null
                    : $validationResult->errors(),
                'draft_validation_warnings' => $validationResult->warnings() !== []
                    ? $validationResult->warnings()
                    : null,
                'draft_updated_at' => now(),
            ];

            if ($createdBy !== null) {
                $attributes['draft_updated_by'] = $createdBy;
            }

            return Workflow::query()->create($attributes);
        });
    }

    private function assertValidNewKey(string $newKey): void
    {
        if ($newKey === '') {
            throw new WorkflowInvalidStateException('Workflow key is required.');
        }

        if (preg_match(WorkflowDefinitionSchema::KEY_PATTERN, $newKey) !== 1) {
            throw new WorkflowInvalidStateException('Workflow key must match /^[a-z0-9_]+$/ pattern.');
        }

        if (Workflow::query()->where('key', $newKey)->exists()) {
            throw new WorkflowKeyAlreadyExistsException("Workflow key [{$newKey}] already exists.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSourceDefinition(Workflow $source): array
    {
        if ($source->hasDraft()) {
            return $this->snapshotDefinition($source->draftDefinition());
        }

        $publishedDefinition = $source->currentDefinition();

        if ($publishedDefinition !== []) {
            return $this->snapshotDefinition($publishedDefinition);
        }

        return $this->snapshotDefinition(WorkflowDefinitionSchema::emptyDefinition());
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function snapshotDefinition(array $definition): array
    {
        return json_decode(json_encode($definition, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
