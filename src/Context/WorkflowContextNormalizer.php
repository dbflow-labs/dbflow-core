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

namespace DbflowLabs\Core\Context;

use DbflowLabs\Core\Contracts\WorkflowContextInterface;
use DbflowLabs\Core\Enums\ContextNamespace;
use DbflowLabs\Core\Exceptions\InvalidWorkflowContextException;
use Illuminate\Database\Eloquent\Model;

/**
 * Adapts legacy WorkflowContextInterface / flat arrays into namespaced context.
 */
final class WorkflowContextNormalizer
{
    /**
     * @param  array<string, mixed>  $legacyVariables
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $starter
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $workflow
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $userContextOverrides
     */
    public function normalize(
        array $legacyVariables = [],
        array $model = [],
        array $starter = [],
        array $actor = [],
        array $workflow = [],
        array $task = [],
        array $userContextOverrides = [],
    ): NormalizedWorkflowContext {
        $context = $this->sanitizeBag($legacyVariables, 'context');

        if ($userContextOverrides !== []) {
            $overrides = $this->sanitizeBag($userContextOverrides, 'context overrides');
            $this->assertNoSystemNamespaceCollision($overrides);
            $context = array_replace($context, $overrides);
        }

        return new NormalizedWorkflowContext([
            ContextNamespace::Model->value => $this->sanitizeBag($model, 'model'),
            ContextNamespace::Starter->value => $this->sanitizeBag($starter, 'starter'),
            ContextNamespace::Actor->value => $this->sanitizeBag($actor, 'actor'),
            ContextNamespace::Context->value => $context,
            ContextNamespace::Workflow->value => $this->sanitizeBag($workflow, 'workflow'),
            ContextNamespace::Task->value => $this->sanitizeBag($task, 'task'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $starter
     * @param  array<string, mixed>  $actor
     * @param  array<string, mixed>  $model
     */
    public function fromWorkflowContextInterface(
        WorkflowContextInterface $provider,
        array $workflow = [],
        array $task = [],
        array $starter = [],
        array $actor = [],
        array $model = [],
    ): NormalizedWorkflowContext {
        return $this->normalize(
            legacyVariables: $provider->getWorkflowVariables(),
            model: $model,
            starter: $starter,
            actor: $actor,
            workflow: $workflow,
            task: $task,
        );
    }

    /**
     * @param  array<string, mixed>  $bag
     * @return array<string, mixed>
     */
    private function sanitizeBag(array $bag, string $label): array
    {
        $sanitized = [];

        foreach ($bag as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidWorkflowContextException(
                    "Invalid {$label} key: keys must be non-empty strings.",
                );
            }

            if (in_array($key, ContextNamespace::values(), true) && is_array($value)) {
                throw new InvalidWorkflowContextException(
                    "Namespace collision: user context must not redefine system namespace [{$key}].",
                );
            }

            $sanitized[$key] = $this->sanitizeValue($value, "{$label}.{$key}");
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, string $path): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $child) {
                $result[$key] = $this->sanitizeValue($child, $path.'.'.$key);
            }

            return $result;
        }

        if ($value instanceof Model) {
            throw new InvalidWorkflowContextException(
                "Eloquent models cannot be stored in workflow context at [{$path}]. Map explicit fields instead.",
            );
        }

        if (is_object($value)) {
            throw new InvalidWorkflowContextException(
                "Unsupported object in workflow context at [{$path}]. Only scalars, arrays, and null are allowed.",
            );
        }

        if (is_resource($value)) {
            throw new InvalidWorkflowContextException(
                "Resources cannot be stored in workflow context at [{$path}].",
            );
        }

        throw new InvalidWorkflowContextException(
            "Unsupported value type in workflow context at [{$path}].",
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertNoSystemNamespaceCollision(array $overrides): void
    {
        foreach (ContextNamespace::cases() as $namespace) {
            if ($namespace->isSystemManaged() && array_key_exists($namespace->value, $overrides)) {
                throw new InvalidWorkflowContextException(
                    "User context cannot overwrite system namespace [{$namespace->value}].",
                );
            }
        }
    }
}
