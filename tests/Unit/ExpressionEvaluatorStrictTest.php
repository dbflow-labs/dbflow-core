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

namespace DbflowLabs\Core\Tests\Unit;

use DbflowLabs\Core\Exceptions\ExpressionEvaluationException;
use DbflowLabs\Core\Services\ExpressionEvaluator;
use DbflowLabs\Core\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExpressionEvaluatorStrictTest extends TestCase
{
    #[Test]
    public function permissive_mode_returns_false_for_invalid_expression(): void
    {
        config(['dbflow.expression.strict' => false]);

        $evaluator = new ExpressionEvaluator;

        $this->assertFalse($evaluator->evaluate('undefined_var > 1', []));
    }

    #[Test]
    public function strict_mode_throws_for_invalid_expression(): void
    {
        config(['dbflow.expression.strict' => true]);

        $evaluator = new ExpressionEvaluator;

        $this->expectException(ExpressionEvaluationException::class);

        $evaluator->evaluate('undefined_var > 1', []);
    }

    #[Test]
    public function validate_syntax_accepts_valid_expression(): void
    {
        $evaluator = new ExpressionEvaluator;

        $evaluator->validateSyntax('amount > 1000 and status == "approved"');

        $this->assertTrue(true);
    }

    #[Test]
    public function validate_syntax_rejects_invalid_expression(): void
    {
        $evaluator = new ExpressionEvaluator;

        $this->expectException(ExpressionEvaluationException::class);

        $evaluator->validateSyntax('amount >');
    }

    #[Test]
    public function object_variables_cannot_be_used_to_call_methods(): void
    {
        config(['dbflow.expression.strict' => false]);

        $evaluator = new ExpressionEvaluator;
        $object = new class
        {
            public string $secret = 'leaked';

            public function dangerous(): string
            {
                return 'called';
            }
        };

        // Object was stripped from the variable context, so both member access forms resolve
        // to an undefined variable and degrade to false in permissive mode instead of invoking
        // the method or reading the property.
        $this->assertFalse($evaluator->evaluate('model.dangerous()', ['model' => $object]));
        $this->assertFalse($evaluator->evaluate('model.secret == "leaked"', ['model' => $object]));
    }

    #[Test]
    public function object_variables_nested_in_arrays_are_also_stripped(): void
    {
        config(['dbflow.expression.strict' => false]);

        $evaluator = new ExpressionEvaluator;
        $object = new class
        {
            public function dangerous(): string
            {
                return 'called';
            }
        };

        $this->assertFalse($evaluator->evaluate('context.model.dangerous()', [
            'context' => ['model' => $object, 'amount' => 100],
        ]));
    }

    #[Test]
    public function plain_scalar_and_array_variables_still_evaluate_normally(): void
    {
        $evaluator = new ExpressionEvaluator;

        $this->assertTrue($evaluator->evaluate('amount > 1000 and status == "approved"', [
            'amount' => 1500,
            'status' => 'approved',
        ]));
    }
}
