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
use DbflowLabs\Core\Exceptions\WorkflowKeyAlreadyExistsException;
use DbflowLabs\Core\Exceptions\WorkflowTemplateNotFoundException;
use DbflowLabs\Core\Models\Workflow;
use DbflowLabs\Core\Templates\WorkflowTemplateRegistry;
use InvalidArgumentException;

final class CreateWorkflowFromTemplate
{
    public function __construct(
        private readonly WorkflowTemplateRegistry $templateRegistry,
        private readonly CreateWorkflowDraft $createWorkflowDraft,
    ) {}

    public function handle(
        string $templateKey,
        string $workflowKey,
        string $workflowName,
        int|string|null $createdBy = null,
    ): Workflow {
        $template = $this->templateRegistry->find($templateKey);

        if ($template === null) {
            throw new WorkflowTemplateNotFoundException("Workflow template [{$templateKey}] not found.");
        }

        $normalizedKey = trim($workflowKey);
        $normalizedName = trim($workflowName);

        if ($normalizedKey === '') {
            throw new InvalidArgumentException('Workflow key is required.');
        }

        if (! preg_match(WorkflowDefinitionSchema::KEY_PATTERN, $normalizedKey)) {
            throw new InvalidArgumentException('Workflow key must match /^[a-z0-9_]+$/ pattern.');
        }

        if ($normalizedName === '') {
            throw new InvalidArgumentException('Workflow name is required.');
        }

        if (Workflow::query()->where('key', $normalizedKey)->exists()) {
            throw new WorkflowKeyAlreadyExistsException("Workflow key [{$normalizedKey}] already exists.");
        }

        $definition = $this->definitionFromTemplate($template['definition'], $normalizedKey, $normalizedName);

        return $this->createWorkflowDraft->handle($definition, $createdBy);
    }

    /**
     * @param  array<string, mixed>  $templateDefinition
     * @return array<string, mixed>
     */
    private function definitionFromTemplate(array $templateDefinition, string $workflowKey, string $workflowName): array
    {
        $definition = json_decode(json_encode($templateDefinition, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $definition[WorkflowDefinitionSchema::FIELD_KEY] = $workflowKey;
        $definition[WorkflowDefinitionSchema::FIELD_NAME] = $workflowName;

        return $definition;
    }
}
