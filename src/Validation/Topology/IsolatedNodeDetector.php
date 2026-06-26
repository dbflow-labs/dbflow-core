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

final class IsolatedNodeDetector
{
    /**
     * @return list<array{path: string, code: string, message: string}>
     */
    public function detect(WorkflowGraph $graph): array
    {
        $violations = [];

        foreach ($graph->nodeIndexByKey() as $nodeKey => $index) {
            if ($graph->incomingCount($nodeKey) !== 0 || $graph->outgoingCount($nodeKey) !== 0) {
                continue;
            }

            $violations[] = [
                'path' => 'nodes.'.$index,
                'code' => 'isolated_node',
                'message' => "Node [{$nodeKey}] is isolated (no incoming or outgoing transitions).",
            ];
        }

        return $violations;
    }
}
