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

final class SaveWorkflowDraft
{
    public function __construct(
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public function handle(
        Workflow $workflow,
        array $definition,
        int|string|null $updatedBy = null,
    ): Workflow {
        return DB::transaction(function () use ($workflow, $definition, $updatedBy): Workflow {
            // Draft save uses non-strict mode, allowing empty assignees.value fields (warnings only)
            $validationResult = $this->validator->validate($definition, strict: false);

            $workflow->draft_definition = $definition;
            $workflow->draft_validation_errors = $validationResult->isValid()
                ? null
                : $validationResult->errors();
            $workflow->draft_validation_warnings = $validationResult->warnings() !== []
                ? $validationResult->warnings()
                : null;
            $workflow->draft_updated_at = now();

            if ($updatedBy !== null) {
                $workflow->draft_updated_by = $updatedBy;
            }

            $workflow->save();

            return $workflow->refresh();
        });
    }
}
