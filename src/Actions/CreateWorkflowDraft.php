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

use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Support\Facades\DB;

final class CreateWorkflowDraft
{
    public function __construct(
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public function handle(
        array $definition,
        ?int $createdBy = null,
    ): Workflow {
        return DB::transaction(function () use ($definition, $createdBy): Workflow {
            $validationResult = $this->validator->validate($definition);

            $attributes = [
                'key' => $definition['key'],
                'name' => $definition['name'],
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
}
