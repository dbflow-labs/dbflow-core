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

final class WorkflowDefinitionDiffBuilder
{
    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array{
     *     summary: array{nodes_added: int, nodes_removed: int, nodes_changed: int, transitions_added: int, transitions_removed: int, transitions_changed: int},
     *     nodes: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{key: string, from: array<string, mixed>, to: array<string, mixed>}>, unchanged: list<array<string, mixed>>},
     *     transitions: array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{from: string, to: string, from_transition: array<string, mixed>, to_transition: array<string, mixed>}>, unchanged: list<array<string, mixed>>},
     *     metadata: array{from_key: string|null, to_key: string|null, from_name: string|null, to_name: string|null}
     * }
     */
    public function diff(array $from, array $to): array
    {
        $fromNodes = is_array($from['nodes'] ?? null) ? $from['nodes'] : [];
        $toNodes = is_array($to['nodes'] ?? null) ? $to['nodes'] : [];
        $fromTransitions = is_array($from['transitions'] ?? null) ? $from['transitions'] : [];
        $toTransitions = is_array($to['transitions'] ?? null) ? $to['transitions'] : [];

        $nodeDiff = $this->diffNodes($fromNodes, $toNodes);
        $transitionDiff = $this->diffTransitions($fromTransitions, $toTransitions);

        return [
            'summary' => [
                'nodes_added' => count($nodeDiff['added']),
                'nodes_removed' => count($nodeDiff['removed']),
                'nodes_changed' => count($nodeDiff['changed']),
                'transitions_added' => count($transitionDiff['added']),
                'transitions_removed' => count($transitionDiff['removed']),
                'transitions_changed' => count($transitionDiff['changed']),
            ],
            'nodes' => $nodeDiff,
            'transitions' => $transitionDiff,
            'metadata' => [
                'from_key' => is_string($from['key'] ?? null) ? $from['key'] : null,
                'to_key' => is_string($to['key'] ?? null) ? $to['key'] : null,
                'from_name' => is_string($from['name'] ?? null) ? $from['name'] : null,
                'to_name' => is_string($to['name'] ?? null) ? $to['name'] : null,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fromNodes
     * @param  list<array<string, mixed>>  $toNodes
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{key: string, from: array<string, mixed>, to: array<string, mixed>}>, unchanged: list<array<string, mixed>>}
     */
    private function diffNodes(array $fromNodes, array $toNodes): array
    {
        $fromMap = $this->nodeMap($fromNodes);
        $toMap = $this->nodeMap($toNodes);

        $fromKeys = array_keys($fromMap);
        $toKeys = array_keys($toMap);

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = [];

        // Added: in $to but not $from
        foreach (array_diff($toKeys, $fromKeys) as $key) {
            $added[] = $toMap[$key];
        }

        // Removed: in $from but not $to
        foreach (array_diff($fromKeys, $toKeys) as $key) {
            $removed[] = $fromMap[$key];
        }

        // Changed / Unchanged: key exists in both
        foreach (array_intersect($fromKeys, $toKeys) as $key) {
            $normalizedFrom = $this->normalizeNode($fromMap[$key]);
            $normalizedTo = $this->normalizeNode($toMap[$key]);

            if ($normalizedFrom !== $normalizedTo) {
                $changed[] = [
                    'key' => $key,
                    'from' => $fromMap[$key],
                    'to' => $toMap[$key],
                ];
            } else {
                $unchanged[] = $fromMap[$key];
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fromTransitions
     * @param  list<array<string, mixed>>  $toTransitions
     * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array{from: string, to: string, from_transition: array<string, mixed>, to_transition: array<string, mixed>}>, unchanged: list<array<string, mixed>>}
     */
    private function diffTransitions(array $fromTransitions, array $toTransitions): array
    {
        $fromMap = $this->transitionMap($fromTransitions);
        $toMap = $this->transitionMap($toTransitions);

        $fromKeys = array_keys($fromMap);
        $toKeys = array_keys($toMap);

        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = [];

        // Added: in $to but not $from
        foreach (array_diff($toKeys, $fromKeys) as $key) {
            $added[] = $toMap[$key];
        }

        // Removed: in $from but not $to
        foreach (array_diff($fromKeys, $toKeys) as $key) {
            $removed[] = $fromMap[$key];
        }

        // Changed / Unchanged: key exists in both
        foreach (array_intersect($fromKeys, $toKeys) as $key) {
            $normalizedFrom = $this->normalizeTransition($fromMap[$key]);
            $normalizedTo = $this->normalizeTransition($toMap[$key]);

            if ($normalizedFrom !== $normalizedTo) {
                $parts = explode('â†?, $key);

                $changed[] = [
                    'from' => $parts[0] ?? '',
                    'to' => $parts[1] ?? '',
                    'from_transition' => $fromMap[$key],
                    'to_transition' => $toMap[$key],
                ];
            } else {
                $unchanged[] = $fromMap[$key];
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, array<string, mixed>>
     */
    private function nodeMap(array $nodes): array
    {
        $map = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $key = is_string($node['key'] ?? null) ? $node['key'] : null;

            if ($key === null || $key === '') {
                continue;
            }

            $map[$key] = $node;
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $transitions
     * @return array<string, array<string, mixed>>
     */
    private function transitionMap(array $transitions): array
    {
        $map = [];

        foreach ($transitions as $transition) {
            if (! is_array($transition)) {
                continue;
            }

            $from = is_string($transition['from'] ?? null) ? $transition['from'] : '';
            $to = is_string($transition['to'] ?? null) ? $transition['to'] : '';

            if ($from === '' && $to === '') {
                continue;
            }

            $key = "{$from}â†’{$to}";
            $map[$key] = $transition;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function normalizeNode(array $node): string
    {
        $comparable = [
            'key' => $node['key'] ?? null,
            'type' => $node['type'] ?? null,
            'name' => $node['name'] ?? null,
            'config' => is_array($node['config'] ?? null) ? $this->sortRecursive($node['config']) : [],
            'metadata' => is_array($node['metadata'] ?? null) ? $this->sortRecursive($node['metadata']) : [],
            'position' => is_array($node['position'] ?? null) ? $this->sortRecursive($node['position']) : [],
        ];

        return $this->stableJsonEncode($comparable);
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    private function normalizeTransition(array $transition): string
    {
        $comparable = [
            'from' => $transition['from'] ?? null,
            'to' => $transition['to'] ?? null,
            'condition' => isset($transition['condition']) && is_string($transition['condition']) ? $transition['condition'] : null,
            'priority' => isset($transition['priority']) ? $transition['priority'] : null,
            'is_default' => isset($transition['is_default']) ? (bool) $transition['is_default'] : false,
        ];

        return $this->stableJsonEncode($comparable);
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function sortRecursive(array $array): array
    {
        ksort($array);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->sortRecursive($value);
            }
        }

        return $array;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stableJsonEncode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
