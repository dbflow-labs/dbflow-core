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

use DbflowLabs\Core\Exceptions\ExpressionEvaluationException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Throwable;

/**
 * Sandbox evaluator for workflow condition expressions.
 *
 * Uses Symfony ExpressionLanguage as the DSL backend for stronger expression capabilities
 * than a hand-rolled parser, while constraining execution via:
 *
 * - No custom functions registered (no direct PHP function access);
 * - Variables only from the caller-supplied $variables whitelist; no global state exposed;
 * - All exceptions (syntax errors, missing variables, etc.) are caught and silently return false without interrupting the flow.
 *
 * Supported syntax examples:
 *   total_amount > 5000
 *   status == "approved"
 *   level >= 3 and department == "finance"
 *   amount > 1000 or is_urgent == true
 */
final class ExpressionEvaluator
{
    private readonly ExpressionLanguage $expressionLanguage;

    public function __construct()
    {
        // No providers passed - ensures no extra functions are injected, reducing sandbox attack surface.
        $this->expressionLanguage = new ExpressionLanguage;
    }

    /**
     * Evaluate an expression in the given variable context and return a boolean result.
     *
     * Any syntax error, undefined variable, or runtime exception returns false rather than throwing,
     * so misconfigured condition nodes do not crash the entire workflow process.
     *
     * @param  array<string, mixed>  $variables  Business context variables; only this whitelist may be referenced by expressions
     */
    public function evaluate(string $expression, array $variables): bool
    {
        if (trim($expression) === '') {
            return false;
        }

        try {
            $result = $this->expressionLanguage->evaluate($expression, $variables);

            return (bool) $result;
        } catch (Throwable $e) {
            if ($this->isStrict()) {
                throw new ExpressionEvaluationException(
                    'Failed to evaluate workflow condition expression: '.$e->getMessage(),
                    $expression,
                    $e,
                );
            }

            // Syntax errors, undefined variables, type mismatches, etc. degrade to false
            // to avoid workflow definition flaws blocking the entire business process.
            return false;
        }
    }

    public function validateSyntax(string $expression): void
    {
        if (trim($expression) === '') {
            return;
        }

        try {
            $this->expressionLanguage->lint($expression, null);
        } catch (Throwable $e) {
            throw new ExpressionEvaluationException(
                'Invalid workflow condition expression syntax: '.$e->getMessage(),
                $expression,
                $e,
            );
        }
    }

    private function isStrict(): bool
    {
        return (bool) config('dbflow.expression.strict', false);
    }
}
