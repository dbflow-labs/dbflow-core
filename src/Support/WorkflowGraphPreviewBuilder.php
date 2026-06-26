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

final class WorkflowGraphPreviewBuilder
{
    private const LAYOUT_START_X = 100;

    private const LAYOUT_START_Y = 120;

    private const LAYOUT_X_SPACING = 260;

    /**
     * @return array{
     *     nodes: list<array{key: string, type: string, name: string, x: int, y: int, config: array<string, mixed>, incoming: list<array<string, mixed>>, outgoing: list<array<string, mixed>>, visual: array<string, mixed>, metadata: array<string, mixed>}>,
     *     edges: list<array{from: string, to: string, label: string, condition: string|null, is_default: bool}>,
     *     legend: list<array<string, mixed>>,
     *     warnings: list<string>
     * }
     */
    public function build(array $definition): array
    {
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        $transitions = is_array($definition['transitions'] ?? null) ? $definition['transitions'] : [];

        $nodeKeys = [];
        $builtNodes = [];
        $warnings = [];
        $autoLayoutIndex = 0;

        foreach ($this->layoutOrder($nodes) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $key = is_string($node['key'] ?? null) ? $node['key'] : null;

            if ($key === null || $key === '') {
                continue;
            }

            $nodeKeys[$key] = true;
            $position = is_array($node['position'] ?? null) ? $node['position'] : [];

            $fallbackX = self::LAYOUT_START_X + ($autoLayoutIndex * self::LAYOUT_X_SPACING);
            $x = $this->resolveCoordinate($position, 'x', $fallbackX);
            $y = $this->resolveCoordinate($position, 'y', self::LAYOUT_START_Y);

            if (! array_key_exists('x', $position)) {
                $autoLayoutIndex++;
            }

            $nodeType = is_string($node['type'] ?? null) ? $node['type'] : 'unknown';

            $builtNodes[] = [
                'key' => $key,
                'type' => $nodeType,
                'name' => is_string($node['name'] ?? null) ? $node['name'] : $key,
                'x' => $x,
                'y' => $y,
                'config' => is_array($node['config'] ?? null) ? $node['config'] : [],
                'incoming' => [],
                'outgoing' => [],
                'visual' => WorkflowNodeVisualRegistry::forType($nodeType),
                'metadata' => [],
            ];
        }

        $builtEdges = [];

        foreach ($transitions as $transition) {
            if (! is_array($transition)) {
                continue;
            }

            $from = is_string($transition['from'] ?? null) ? $transition['from'] : '';
            $to = is_string($transition['to'] ?? null) ? $transition['to'] : '';

            if ($from !== '' && ! isset($nodeKeys[$from])) {
                $warnings[] = "Transition references missing source node [{$from}].";
            }

            if ($to !== '' && ! isset($nodeKeys[$to])) {
                $warnings[] = "Transition references missing target node [{$to}].";
            }

            $condition = $transition['condition'] ?? null;
            $conditionLabel = is_string($condition) && $condition !== '' ? $condition : null;
            $isDefault = (bool) ($transition['is_default'] ?? false);

            $builtEdges[] = [
                'from' => $from,
                'to' => $to,
                'label' => $conditionLabel ?? '',
                'condition' => $conditionLabel,
                'is_default' => $isDefault,
            ];
        }

        // Compute incoming / outgoing for each node
        foreach ($builtEdges as $edge) {
            $fromKey = $edge['from'];
            $toKey = $edge['to'];

            foreach ($builtNodes as &$builtNode) {
                if ($builtNode['key'] === $toKey && $fromKey !== '') {
                    $builtNode['incoming'][] = $edge;
                }

                if ($builtNode['key'] === $fromKey && $toKey !== '') {
                    $builtNode['outgoing'][] = $edge;
                }
            }

            unset($builtNode);
        }

        return [
            'nodes' => $builtNodes,
            'edges' => $builtEdges,
            'legend' => WorkflowNodeVisualRegistry::all(),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private function layoutOrder(array $nodes): array
    {
        $ordered = [];
        $startNode = null;
        $others = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = is_string($node['type'] ?? null) ? $node['type'] : null;

            if ($type === 'start' && $startNode === null) {
                $startNode = $node;

                continue;
            }

            $others[] = $node;
        }

        if ($startNode !== null) {
            $ordered[] = $startNode;
        }

        return array_merge($ordered, $others);
    }

    /**
     * @param  array<string, mixed>  $position
     */
    private function resolveCoordinate(array $position, string $axis, int $fallback): int
    {
        if (! array_key_exists($axis, $position)) {
            return $fallback;
        }

        $value = $position[$axis];

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return $fallback;
    }
}
