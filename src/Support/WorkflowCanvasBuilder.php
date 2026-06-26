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

namespace DbflowLabs\Core\Support;

final class WorkflowCanvasBuilder
{
    public function __construct(
        private readonly WorkflowGraphPreviewBuilder $graphPreviewBuilder,
    ) {}

    /**
     * @return array{
     *     nodes: list<array{key: string, type: string, name: string, x: int, y: int, visual: array<string, mixed>}>,
     *     edges: list<array{from: string, to: string, condition: string|null, is_default: bool}>,
     *     definition: array<string, mixed>
     * }
     */
    public function build(array $definition): array
    {
        $graph = $this->graphPreviewBuilder->build($definition);

        $nodes = [];

        foreach ($graph['nodes'] as $node) {
            $nodes[] = [
                'key' => $node['key'],
                'type' => $node['type'],
                'name' => $node['name'],
                'x' => $node['x'],
                'y' => $node['y'],
                'visual' => $node['visual'],
            ];
        }

        $edges = [];

        foreach ($graph['edges'] as $edge) {
            $edges[] = [
                'from' => $edge['from'],
                'to' => $edge['to'],
                'condition' => $edge['condition'],
                'is_default' => $edge['is_default'],
            ];
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'definition' => $this->copyDefinition($definition),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function copyDefinition(array $definition): array
    {
        $copy = json_decode(json_encode($definition, JSON_THROW_ON_ERROR), true);

        return is_array($copy) ? $copy : [];
    }
}
