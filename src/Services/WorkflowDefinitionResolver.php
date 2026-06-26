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

namespace DbflowLabs\Core\Services;

use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Resolvers\PublishedWorkflowDefinitionResolver;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;

final class WorkflowDefinitionResolver
{
    private readonly PublishedWorkflowDefinitionResolver $publishedResolver;

    public function __construct(
        ?PublishedWorkflowDefinitionResolver $publishedResolver = null,
    ) {
        $this->publishedResolver = $publishedResolver ?? new PublishedWorkflowDefinitionResolver(
            new WorkflowDefinitionValidator,
        );
    }

    public function activeVersion(string $workflowKey): WorkflowVersion
    {
        return $this->publishedResolver->resolve($workflowKey);
    }
}
