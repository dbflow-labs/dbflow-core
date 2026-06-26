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

namespace DbflowLabs\Core\Validation\Topology;

use DbflowLabs\Core\Definitions\Nodes\ConditionNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;

final class TerminationAnalyzer
{
    public function __construct(
        private readonly ReachabilityAnalyzer $reachabilityAnalyzer = new ReachabilityAnalyzer,
    ) {}

    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function analyze(WorkflowGraph $graph): array
    {
        $canReachEnd = $this->nodesThatCanReachEnd($graph);
        $violations = [];

        $startNodeKeys = $graph->startNodeKeys();

        if ($startNodeKeys === []) {
            return $violations;
        }

        $reachableFromStart = $this->reachabilityAnalyzer->forwardReachable($graph, $startNodeKeys[0]);

        foreach ($reachableFromStart as $nodeKey => $_) {
            $nodeType = $graph->nodeTypeByKey()[$nodeKey] ?? null;

            if ($nodeType === WorkflowDefinitionSchema::NODE_TYPE_END) {
                continue;
            }

            if (isset($canReachEnd[$nodeKey])) {
                continue;
            }

            $violations[] = [
                'path' => 'nodes.'.$graph->nodeIndexByKey()[$nodeKey],
                'code' => 'dead_end',
                'message' => "Node [{$nodeKey}] does not lead to any end node.",
            ];
        }

        $violations = array_merge($violations, $this->validateConditionBranchTermination($graph, $canReachEnd));

        return $violations;
    }

    /**
     * @param  array<string, true>  $canReachEnd
     * @return list<array{path: string, code: string, message: string}>
     */
    private function validateConditionBranchTermination(WorkflowGraph $graph, array $canReachEnd): array
    {
        $violations = [];

        foreach ($graph->blueprint()->nodes() as $index => $node) {
            if (! $node instanceof ConditionNode) {
                continue;
            }

            $outgoing = $graph->outgoingTransitions($node->key());

            if ($outgoing === []) {
                continue;
            }

            if (count($outgoing) > 1) {
                $hasDefault = false;
                $allHaveCondition = true;

                foreach ($outgoing as $transition) {
                    if ($transition->isDefault()) {
                        $hasDefault = true;
                    }

                    $condition = $transition->condition();

                    if ($condition === null || trim($condition) === '') {
                        $allHaveCondition = false;
                    }
                }

                if (! $hasDefault && ! $allHaveCondition) {
                    continue;
                }
            }

            foreach ($outgoing as $transition) {
                $target = $transition->to();

                if ($target === '') {
                    continue;
                }

                if (isset($canReachEnd[$target])) {
                    continue;
                }

                $violations[] = [
                    'path' => 'nodes.'.$index.'.transitions',
                    'code' => 'condition_branch_dead_end',
                    'message' => "Condition node [{$node->key()}] branch to [{$target}] does not lead to any end node.",
                ];
            }
        }

        return $violations;
    }

    /**
     * @return array<string, true>
     */
    private function nodesThatCanReachEnd(WorkflowGraph $graph): array
    {
        $canReachEnd = [];
        $queue = $graph->endNodeKeys();

        foreach ($queue as $endKey) {
            $canReachEnd[$endKey] = true;
        }

        $head = 0;

        while ($head < count($queue)) {
            $current = $queue[$head];
            $head++;

            foreach ($graph->predecessors($current) as $predecessor) {
                if (isset($canReachEnd[$predecessor])) {
                    continue;
                }

                $canReachEnd[$predecessor] = true;
                $queue[] = $predecessor;
            }
        }

        return $canReachEnd;
    }
}
