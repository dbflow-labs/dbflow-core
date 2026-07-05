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

namespace DbflowLabs\Core\Console\Commands;

use DbflowLabs\Core\Models\WorkflowVersion;
use DbflowLabs\Core\Services\ExpressionEvaluator;
use DbflowLabs\Core\Services\WorkflowDefinitionRegistry;
use DbflowLabs\Core\Validation\WorkflowDefinitionValidator;
use Illuminate\Console\Command;

final class ValidateWorkflowDefinitionsCommand extends Command
{
    protected $signature = 'dbflow:validate
                            {--workflow= : Validate only the given workflow key}
                            {--strict : Validate using strict blueprint rules}
                            {--source=registry : Validate registry providers (registry) or active database versions (database)}';

    protected $description = 'Validate workflow definitions from the registry or active database versions';

    public function handle(
        WorkflowDefinitionRegistry $registry,
        WorkflowDefinitionValidator $validator,
        ExpressionEvaluator $expressionEvaluator,
    ): int {
        $workflowKey = $this->option('workflow');
        $workflowKey = is_string($workflowKey) && $workflowKey !== '' ? $workflowKey : null;
        $strict = (bool) $this->option('strict');
        $source = (string) $this->option('source');

        $definitions = $source === 'database'
            ? $this->definitionsFromDatabase($workflowKey)
            : $this->definitionsFromRegistry($registry, $workflowKey);

        if ($definitions === []) {
            $this->error('No workflow definitions found to validate.');

            return self::FAILURE;
        }

        $hasErrors = false;

        foreach ($definitions as $key => $definition) {
            $result = $validator->validate($definition, $strict);

            foreach ($result->errors() as $error) {
                $hasErrors = true;
                $this->error("[{$key}] {$error['message']}");
            }

            foreach ($result->warnings() as $warning) {
                $this->warn("[{$key}] {$warning['message']}");
            }

            $expressionErrors = $this->validateTransitionExpressions($definition, $expressionEvaluator);

            foreach ($expressionErrors as $expressionError) {
                $hasErrors = true;
                $this->error("[{$key}] {$expressionError}");
            }

            if (! $hasErrors && $result->errors() === [] && $expressionErrors === []) {
                $this->info("[{$key}] valid");
            }
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitionsFromRegistry(
        WorkflowDefinitionRegistry $registry,
        ?string $workflowKey,
    ): array {
        $definitions = [];

        foreach ($registry->providers() as $provider) {
            if ($workflowKey !== null && $provider->key() !== $workflowKey) {
                continue;
            }

            $definitions[$provider->key()] = $provider->definition();
        }

        return $definitions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitionsFromDatabase(?string $workflowKey): array
    {
        $definitions = [];

        $query = WorkflowVersion::query()
            ->where('is_active', true)
            ->with('workflow');

        if ($workflowKey !== null) {
            $query->whereHas('workflow', static function ($builder) use ($workflowKey): void {
                $builder->where('key', $workflowKey);
            });
        }

        foreach ($query->get() as $version) {
            $key = $version->workflow?->key;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $definitions[$key] = $version->definition();
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private function validateTransitionExpressions(array $definition, ExpressionEvaluator $expressionEvaluator): array
    {
        $errors = [];
        $transitions = $definition['transitions'] ?? [];

        if (! is_array($transitions)) {
            return $errors;
        }

        foreach ($transitions as $index => $transition) {
            if (! is_array($transition)) {
                continue;
            }

            $expression = $transition['condition'] ?? $transition['expression'] ?? null;

            if (! is_string($expression) || trim($expression) === '') {
                continue;
            }

            try {
                $expressionEvaluator->validateSyntax($expression);
            } catch (\Throwable $e) {
                $errors[] = "Transition #{$index} has invalid condition expression [{$expression}]: {$e->getMessage()}";
            }
        }

        return $errors;
    }
}
