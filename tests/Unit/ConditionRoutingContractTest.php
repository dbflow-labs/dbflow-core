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

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Services\ExpressionEvaluator;
use DbflowLabs\Core\Services\TransitionResolver;
use DbflowLabs\Core\Tests\TestCase;
use DbflowLabs\Core\Validation\BlueprintValidator;
use PHPUnit\Framework\Attributes\Test;

final class ConditionRoutingContractTest extends TestCase
{
    #[Test]
    public function transition_resolver_evaluates_outgoing_transition_conditions(): void
    {
        $blueprint = Blueprint::fromArray($this->transitionConditionDefinition());
        $resolver = new TransitionResolver(new ExpressionEvaluator);

        $next = $resolver->nextNode($blueprint, 'start', variables: ['amount' => 15000]);

        $this->assertNotNull($next);
        $this->assertSame('high_value_review', $next->key());
    }

    #[Test]
    public function transition_resolver_ignores_condition_node_expression_when_transition_conditions_exist(): void
    {
        $definition = $this->transitionConditionDefinition();
        $definition['nodes'][1]['config']['expression'] = 'amount < 1';

        $blueprint = Blueprint::fromArray($definition);
        $resolver = new TransitionResolver(new ExpressionEvaluator);

        $next = $resolver->nextNode($blueprint, 'start', variables: ['amount' => 15000]);

        $this->assertSame('high_value_review', $next->key());
    }

    #[Test]
    public function validator_rejects_expression_only_condition_routing(): void
    {
        $definition = [
            'key' => 'expression_only_flow',
            'name' => 'Expression Only Flow',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'amount_gate',
                    'type' => 'condition',
                    'name' => 'Amount Gate',
                    'config' => ['expression' => 'amount > 10000'],
                ],
                ['key' => 'end_fast_track', 'type' => 'end', 'name' => 'Fast Track'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'amount_gate'],
                ['from' => 'amount_gate', 'to' => 'end_fast_track'],
            ],
        ];

        $result = (new BlueprintValidator)->validateArray($definition);

        $this->assertFalse($result->isValid());
        $this->assertTrue($this->hasErrorCode($result->errors(), 'ambiguous_condition_routing'));
    }

    #[Test]
    public function metadata_does_not_change_condition_routing(): void
    {
        $definition = $this->transitionConditionDefinition();
        $definition['nodes'][1][WorkflowDefinitionSchema::FIELD_METADATA] = [
            'expression' => 'amount < 1',
            'canvas' => 'dbflow-filament-pro',
        ];

        $blueprint = Blueprint::fromArray($definition);
        $resolver = new TransitionResolver(new ExpressionEvaluator);

        $next = $resolver->nextNode($blueprint, 'start', variables: ['amount' => 2500]);

        $this->assertSame('end_fast_track', $next?->key());
    }

    /**
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function hasErrorCode(array $errors, string $code): bool
    {
        foreach ($errors as $error) {
            if (($error['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function transitionConditionDefinition(): array
    {
        return [
            'key' => 'condition_contract_flow',
            'name' => 'Condition Contract Flow',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start'],
                [
                    'key' => 'amount_gate',
                    'type' => 'condition',
                    'name' => 'Amount Gate',
                    'config' => ['expression' => 'legacy documentation only'],
                ],
                [
                    'key' => 'high_value_review',
                    'type' => 'approval',
                    'name' => 'High Value Review',
                    'config' => [
                        'approval_mode' => 'any',
                        'assignees' => ['type' => 'user', 'value' => '1'],
                    ],
                ],
                ['key' => 'end_fast_track', 'type' => 'end', 'name' => 'Fast Track'],
                ['key' => 'end_standard', 'type' => 'end', 'name' => 'Standard'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'amount_gate'],
                ['from' => 'amount_gate', 'to' => 'high_value_review', 'condition' => 'amount > 10000'],
                ['from' => 'amount_gate', 'to' => 'end_fast_track', 'is_default' => true],
                ['from' => 'high_value_review', 'to' => 'end_standard'],
            ],
        ];
    }
}
