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

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;

final class ReachabilityAnalyzer
{
    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function unreachableFromStart(WorkflowGraph $graph): array
    {
        $startNodeKeys = $graph->startNodeKeys();

        if ($startNodeKeys === []) {
            return [];
        }

        $reachable = $this->forwardReachable($graph, $startNodeKeys[0]);
        $violations = [];

        foreach ($graph->nodeTypeByKey() as $nodeKey => $nodeType) {
            if ($nodeType === WorkflowDefinitionSchema::NODE_TYPE_START) {
                continue;
            }

            if (isset($reachable[$nodeKey])) {
                continue;
            }

            $violations[] = [
                'path' => 'nodes.'.$graph->nodeIndexByKey()[$nodeKey],
                'code' => 'unreachable',
                'message' => 'Node is not reachable from the start node.',
            ];
        }

        return $violations;
    }

    /**
     * @return array<string, true>
     */
    public function forwardReachable(WorkflowGraph $graph, string $startNodeKey): array
    {
        $reachable = [$startNodeKey => true];
        $queue = [$startNodeKey];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === null) {
                continue;
            }

            foreach ($graph->successors($current) as $neighbor) {
                if (isset($reachable[$neighbor])) {
                    continue;
                }

                $reachable[$neighbor] = true;
                $queue[] = $neighbor;
            }
        }

        return $reachable;
    }
}
