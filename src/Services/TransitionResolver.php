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

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Contracts\WorkflowNodeInterface;
use DbflowLabs\Core\Definitions\Nodes\ConditionNode;
use DbflowLabs\Core\Definitions\Nodes\StartNode;
use DbflowLabs\Core\Definitions\Transition;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;

final class TransitionResolver
{
    /**
     * Maximum recursion depth when traversing condition nodes to prevent infinite loops in cyclic graphs.
     */
    private const MAX_CONDITION_DEPTH = 10;

    private readonly ExpressionEvaluator $expressionEvaluator;

    public function __construct(?ExpressionEvaluator $expressionEvaluator = null)
    {
        $this->expressionEvaluator = $expressionEvaluator ?? new ExpressionEvaluator;
    }

    public function startNode(Blueprint $blueprint): StartNode
    {
        return $blueprint->findStartNode();
    }

    /**
     * From $fromNodeKey, follow transitions to find the next landable node.
     *
     * If the next node is a condition gateway, outgoing transition conditions are evaluated
     * against $variables. ConditionNode.expression is not used for routing.
     *
     * @param  array<string, mixed>  $variables  Business variables used for condition node expression evaluation
     */
    public function nextNode(
        Blueprint $blueprint,
        string $fromNodeKey,
        string $event = 'approve',
        array $variables = [],
    ): ?WorkflowNodeInterface {
        return $this->resolveNextNode($blueprint, $fromNodeKey, $event, $variables, 0);
    }

    public function findNode(Blueprint $blueprint, string $nodeKey): ?WorkflowNodeInterface
    {
        return $blueprint->findNode($nodeKey);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function resolveNextNode(
        Blueprint $blueprint,
        string $fromNodeKey,
        string $event,
        array $variables,
        int $depth,
    ): ?WorkflowNodeInterface {
        if ($depth > self::MAX_CONDITION_DEPTH) {
            throw new InvalidWorkflowDefinitionException(
                'Workflow definition has a condition loop exceeding the maximum depth of '.self::MAX_CONDITION_DEPTH.'.',
            );
        }

        $toKey = $this->pickTransitionTarget($blueprint, $fromNodeKey, $event);

        if ($toKey === null) {
            return null;
        }

        $node = $blueprint->findNode($toKey);

        if ($node === null) {
            return null;
        }

            if ($node instanceof ConditionNode) {
                $branchKey = $this->evaluateConditionGateway($blueprint, $node, $variables);

            if ($branchKey === null) {
                return null;
            }

            $branchNode = $blueprint->findNode($branchKey);

            if ($branchNode === null) {
                return null;
            }

            if ($branchNode instanceof ConditionNode) {
                return $this->resolveNextNode($blueprint, $branchKey, $event, $variables, $depth + 1);
            }

            return $branchNode;
        }

        return $node;
    }

    private function pickTransitionTarget(
        Blueprint $blueprint,
        string $fromNodeKey,
        string $event,
    ): ?string {
        $transitions = self::sortByPriority($blueprint->transitionsFrom($fromNodeKey));

        foreach ($transitions as $transition) {
            $transitionEvent = $transition->event();

            if ($transitionEvent !== null && $transitionEvent !== $event) {
                continue;
            }

            $to = $transition->to();

            if ($to !== '') {
                return $to;
            }
        }

        return null;
    }

    /**
     * Orders outgoing transitions so Transition.priority controls evaluation order: lower
     * numbers are evaluated first (consistent with sequential assignment ordering elsewhere
     * in the engine). Transitions without an explicit priority are evaluated last, keeping
     * their original relative order (stable sort) so unpriority definitions keep working
     * exactly as before.
     *
     * @param  list<Transition>  $transitions
     * @return list<Transition>
     */
    private static function sortByPriority(array $transitions): array
    {
        $indexed = array_values($transitions);

        usort($indexed, static function ($a, $b): int {
            $priorityA = $a->priority() ?? PHP_INT_MAX;
            $priorityB = $b->priority() ?? PHP_INT_MAX;

            return $priorityA <=> $priorityB;
        });

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function evaluateConditionGateway(
        Blueprint $blueprint,
        ConditionNode $conditionNode,
        array $variables,
    ): ?string {
        $defaultTarget = null;

        foreach (self::sortByPriority($blueprint->transitionsFrom($conditionNode->key())) as $transition) {
            $to = $transition->to();

            if ($to === '') {
                continue;
            }

            if ($transition->isDefault()) {
                $defaultTarget = $to;
            }

            $expression = $transition->condition();

            if ($expression === null || trim($expression) === '') {
                continue;
            }

            if ($this->expressionEvaluator->evaluate(trim($expression), $variables)) {
                return $to;
            }
        }

        return $defaultTarget;
    }
}
