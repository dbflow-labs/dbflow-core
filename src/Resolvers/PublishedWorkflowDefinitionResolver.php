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

namespace DbflowLabs\Core\Resolvers;

use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Exceptions\WorkflowNotAvailableException;
use DbflowLabs\Core\Exceptions\WorkflowNotFoundException;
use DbflowLabs\Core\Exceptions\WorkflowNotPublishedException;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;

final class PublishedWorkflowDefinitionResolver
{
    public function __construct(
        private readonly WorkflowDefinitionValidator $validator,
    ) {}

    public function resolve(string $workflowKey): WorkflowVersion
    {
        $workflow = Workflow::query()
            ->where('key', $workflowKey)
            ->first();

        if ($workflow === null) {
            throw new WorkflowNotFoundException("Workflow [{$workflowKey}] not found or disabled.");
        }

        if ($workflow->isDisabled() || $workflow->isArchived()) {
            throw new WorkflowNotAvailableException("Workflow [{$workflowKey}] is not available for new instances.");
        }

        if (! $workflow->is_enabled) {
            throw new WorkflowNotAvailableException("Workflow [{$workflowKey}] is not available for new instances.");
        }

        if (! $workflow->canStartNewInstances()) {
            throw new WorkflowNotPublishedException("Workflow [{$workflowKey}] has no published version.");
        }

        $version = $this->resolvePublishedVersion($workflow);

        if ($version === null) {
            throw new WorkflowNotPublishedException("Workflow [{$workflowKey}] has no published version.");
        }

        $definition = $version->definition();

        if ($definition === []) {
            throw new InvalidWorkflowDefinitionException('Published workflow definition is empty.');
        }

        $this->assertDefinitionIsValidForRuntime($definition);

        return $version;
    }

    private function resolvePublishedVersion(Workflow $workflow): ?WorkflowVersion
    {
        if ($workflow->current_version_id === null) {
            return null;
        }

        return WorkflowVersion::query()
            ->whereKey($workflow->current_version_id)
            ->where('workflow_id', $workflow->getKey())
            ->first();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function assertDefinitionIsValidForRuntime(array $definition): void
    {
        $this->validator->validateOrFail($definition);
    }
}
