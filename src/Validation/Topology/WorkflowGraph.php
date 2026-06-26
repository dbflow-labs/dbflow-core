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

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Contracts\WorkflowNodeInterface;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Definitions\Nodes\StartNode;
use DbflowLabs\Core\Definitions\Transition;

final class WorkflowGraph
{
    /**
     * @param  array<string, int>  $nodeIndexByKey
     * @param  array<string, string>  $nodeTypeByKey
     * @param  array<string, int>  $incomingByNode
     * @param  array<string, int>  $outgoingByNode
     * @param  array<string, list<string>>  $adjacency
     * @param  array<string, list<string>>  $reverseAdjacency
     * @param  array<string, list<Transition>>  $outgoingTransitionsByNode
     * @param  list<string>  $startNodeKeys
     * @param  list<string>  $endNodeKeys
     */
    public function __construct(
        private readonly Blueprint $blueprint,
        private readonly array $nodeIndexByKey,
        private readonly array $nodeTypeByKey,
        private readonly array $incomingByNode,
        private readonly array $outgoingByNode,
        private readonly array $adjacency,
        private readonly array $reverseAdjacency,
        private readonly array $outgoingTransitionsByNode,
        private readonly array $startNodeKeys,
        private readonly array $endNodeKeys,
    ) {}

    public static function fromBlueprint(Blueprint $blueprint): self
    {
        $nodeIndexByKey = [];
        $nodeTypeByKey = [];
        $incomingByNode = [];
        $outgoingByNode = [];
        $adjacency = [];
        $reverseAdjacency = [];
        $outgoingTransitionsByNode = [];
        $startNodeKeys = [];
        $endNodeKeys = [];

        foreach ($blueprint->nodes() as $index => $node) {
            $key = $node->key();
            $nodeIndexByKey[$key] = $index;
            $nodeTypeByKey[$key] = $node->type()->value;
            $incomingByNode[$key] = 0;
            $outgoingByNode[$key] = 0;
            $adjacency[$key] = [];
            $reverseAdjacency[$key] = [];
            $outgoingTransitionsByNode[$key] = [];

            if ($node instanceof StartNode) {
                $startNodeKeys[] = $key;
            }

            if ($node instanceof EndNode) {
                $endNodeKeys[] = $key;
            }
        }

        foreach ($blueprint->transitions() as $transition) {
            $from = $transition->from();
            $to = $transition->to();

            if ($from === '' || $to === '') {
                continue;
            }

            if (! isset($nodeIndexByKey[$from]) || ! isset($nodeIndexByKey[$to])) {
                continue;
            }

            $incomingByNode[$to] = ($incomingByNode[$to] ?? 0) + 1;
            $outgoingByNode[$from] = ($outgoingByNode[$from] ?? 0) + 1;
            $adjacency[$from][] = $to;
            $reverseAdjacency[$to][] = $from;
            $outgoingTransitionsByNode[$from][] = $transition;
        }

        return new self(
            blueprint: $blueprint,
            nodeIndexByKey: $nodeIndexByKey,
            nodeTypeByKey: $nodeTypeByKey,
            incomingByNode: $incomingByNode,
            outgoingByNode: $outgoingByNode,
            adjacency: $adjacency,
            reverseAdjacency: $reverseAdjacency,
            outgoingTransitionsByNode: $outgoingTransitionsByNode,
            startNodeKeys: $startNodeKeys,
            endNodeKeys: $endNodeKeys,
        );
    }

    public function blueprint(): Blueprint
    {
        return $this->blueprint;
    }

    /**
     * @return array<string, int>
     */
    public function nodeIndexByKey(): array
    {
        return $this->nodeIndexByKey;
    }

    /**
     * @return array<string, string>
     */
    public function nodeTypeByKey(): array
    {
        return $this->nodeTypeByKey;
    }

    public function incomingCount(string $nodeKey): int
    {
        return $this->incomingByNode[$nodeKey] ?? 0;
    }

    public function outgoingCount(string $nodeKey): int
    {
        return $this->outgoingByNode[$nodeKey] ?? 0;
    }

    /**
     * @return list<string>
     */
    public function successors(string $nodeKey): array
    {
        return $this->adjacency[$nodeKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function predecessors(string $nodeKey): array
    {
        return $this->reverseAdjacency[$nodeKey] ?? [];
    }

    /**
     * @return list<Transition>
     */
    public function outgoingTransitions(string $nodeKey): array
    {
        return $this->outgoingTransitionsByNode[$nodeKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function startNodeKeys(): array
    {
        return $this->startNodeKeys;
    }

    /**
     * @return list<string>
     */
    public function endNodeKeys(): array
    {
        return $this->endNodeKeys;
    }

    public function findNode(string $key): ?WorkflowNodeInterface
    {
        return $this->blueprint->findNode($key);
    }
}
