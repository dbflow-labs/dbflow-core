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

use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @internal Definition-management action; not part of the stable 1.0 public API.
 */
final class PublishWorkflowDraft
{
    public function __construct(
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    public function handle(
        Workflow $workflow,
        int|string|null $publishedBy = null,
    ): WorkflowVersion {
        return DB::transaction(function () use ($workflow, $publishedBy): WorkflowVersion {
            if (! $workflow->hasDraft()) {
                throw new InvalidWorkflowDefinitionException('Workflow draft is missing.');
            }

            $definition = $this->snapshotDefinition($workflow->draftDefinition());
            $validationResult = $this->validator->validate($definition);

            if (! $validationResult->isValid()) {
                throw new InvalidWorkflowDefinitionException($validationResult);
            }

            $maxVersion = (int) (WorkflowVersion::query()
                ->where('workflow_id', $workflow->getKey())
                ->max('version') ?? 0);

            $versionAttributes = [
                'workflow_id' => $workflow->getKey(),
                'version' => $maxVersion + 1,
                'definition' => $definition,
                'published_at' => now(),
            ];

            if ($publishedBy !== null && Schema::hasColumn('dbflow_workflow_versions', 'published_by')) {
                $versionAttributes['published_by'] = $publishedBy;
            }

            $workflowVersion = WorkflowVersion::query()->create($versionAttributes);

            if (Schema::hasColumn('dbflow_workflows', 'current_version_id')) {
                $workflow->current_version_id = $workflowVersion->getKey();
            }

            if (Schema::hasColumn('dbflow_workflows', 'status')) {
                $workflow->status = 'published';
            }

            $workflow->draft_validation_errors = null;
            $workflow->draft_validation_warnings = null;
            $workflow->save();

            return $workflowVersion->refresh();
        });
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
