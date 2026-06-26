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

namespace DbflowLabs\Core\Validation;

use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;

final class WorkflowDefinitionValidator
{
    public function __construct(
        private readonly BlueprintValidator $blueprintValidator = new BlueprintValidator,
    ) {}

    public static function withAssigneeResolverRegistry(AssigneeResolverRegistry $registry): self
    {
        return new self(new BlueprintValidator(assigneeResolverRegistry: $registry));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function validate(array $definition, bool $strict = true): WorkflowDefinitionValidationResult
    {
        return $this->blueprintValidator->validateArray($definition, $strict);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function validateOrFail(array $definition): void
    {
        $result = $this->validate($definition, strict: true);

        if (! $result->isValid()) {
            throw new InvalidWorkflowDefinitionException($result);
        }
    }
}
