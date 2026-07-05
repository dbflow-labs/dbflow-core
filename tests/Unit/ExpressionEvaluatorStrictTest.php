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
}
