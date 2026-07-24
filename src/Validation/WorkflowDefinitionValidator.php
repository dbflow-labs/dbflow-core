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

use DbflowLabs\Core\Capabilities\RuntimeCapabilityRegistry;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\Actions\ReliableActionHandlerRegistry;

final class WorkflowDefinitionValidator
{
    private readonly BlueprintValidator $blueprintValidator;

    public function __construct(?BlueprintValidator $blueprintValidator = null)
    {
        $this->blueprintValidator = $blueprintValidator ?? self::defaultBlueprintValidator();
    }

    public static function withAssigneeResolverRegistry(AssigneeResolverRegistry $registry): self
    {
        return new self(new BlueprintValidator(
            assigneeResolverRegistry: $registry,
            runtimeCapabilityRegistry: self::stage11ACapabilityRegistry(),
        ));
    }

    public static function withDependencies(
        AssigneeResolverRegistry $assigneeResolverRegistry,
        RuntimeCapabilityRegistry $runtimeCapabilityRegistry,
        ?ReliableActionHandlerRegistry $reliableActionHandlerRegistry = null,
    ): self {
        return new self(new BlueprintValidator(
            assigneeResolverRegistry: $assigneeResolverRegistry,
            runtimeCapabilityRegistry: $runtimeCapabilityRegistry,
            reliableActionHandlerRegistry: $reliableActionHandlerRegistry,
        ));
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

    private static function defaultBlueprintValidator(): BlueprintValidator
    {
        return new BlueprintValidator(
            runtimeCapabilityRegistry: self::stage11ACapabilityRegistry(),
        );
    }

    private static function stage11ACapabilityRegistry(): RuntimeCapabilityRegistry
    {
        $registry = new RuntimeCapabilityRegistry;
        $registry->registerStage11DDefaults();

        return $registry;
    }
}
