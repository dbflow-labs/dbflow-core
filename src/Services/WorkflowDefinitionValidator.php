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

use DbflowLabs\Core\Validation\WorkflowDefinitionValidator as BlueprintWorkflowDefinitionValidator;

/**
 * @deprecated Use {@see BlueprintWorkflowDefinitionValidator} directly. This wrapper delegates to the DTO/topology validator.
 */
final class WorkflowDefinitionValidator
{
    public function __construct(
        private readonly BlueprintWorkflowDefinitionValidator $validator = new BlueprintWorkflowDefinitionValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public function validate(array $definition): void
    {
        $this->validator->validateOrFail($definition);
    }
}
