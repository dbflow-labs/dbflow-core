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

final class CycleDetector
{
    private const STATE_UNVISITED = 0;

    private const STATE_VISITING = 1;

    private const STATE_VISITED = 2;

    /**
     * @return list<array{path: string, code: string, message: string, cycle_path: list<string>}>
     */
    public function detect(WorkflowGraph $graph): array
    {
        $state = [];
        $violations = [];

        foreach (array_keys($graph->nodeIndexByKey()) as $nodeKey) {
            $state[$nodeKey] = self::STATE_UNVISITED;
        }

        foreach (array_keys($graph->nodeIndexByKey()) as $nodeKey) {
            if ($state[$nodeKey] !== self::STATE_UNVISITED) {
                continue;
            }

            $cyclePath = $this->visit($nodeKey, $graph, $state, []);

            if ($cyclePath !== null) {
                $violations[] = [
                    'path' => 'transitions',
                    'code' => 'cycle_detected',
                    'message' => 'Workflow graph contains a cycle: '.implode(' -> ', $cyclePath).'.',
                    'cycle_path' => $cyclePath,
                ];

                return $violations;
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, int>  $state
     * @param  list<string>  $stack
     * @return list<string>|null
     */
    private function visit(string $nodeKey, WorkflowGraph $graph, array &$state, array $stack): ?array
    {
        $state[$nodeKey] = self::STATE_VISITING;
        $stack[] = $nodeKey;

        foreach ($graph->successors($nodeKey) as $neighbor) {
            if (($state[$neighbor] ?? self::STATE_UNVISITED) === self::STATE_UNVISITED) {
                $cyclePath = $this->visit($neighbor, $graph, $state, $stack);

                if ($cyclePath !== null) {
                    return $cyclePath;
                }

                continue;
            }

            if ($state[$neighbor] === self::STATE_VISITING) {
                $cycleStart = array_search($neighbor, $stack, true);

                if ($cycleStart === false) {
                    return array_merge($stack, [$neighbor]);
                }

                return array_merge(array_slice($stack, $cycleStart), [$neighbor]);
            }
        }

        $state[$nodeKey] = self::STATE_VISITED;

        return null;
    }
}
