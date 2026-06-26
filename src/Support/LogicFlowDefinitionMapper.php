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

use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;

final class LogicFlowDefinitionMapper
{
    private const TYPE_MAP = [
        'start' => 'dbflow-start',
        'approval' => 'dbflow-approval',
        'condition' => 'dbflow-condition',
        'action' => 'dbflow-action',
        'end' => 'dbflow-end',
    ];

    /**
     * @param  array<string, mixed>  $definition
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function toLogicFlow(array $definition): array
    {
        $nodes = [];
        $definitionNodes = is_array($definition[WorkflowDefinitionSchema::FIELD_NODES] ?? null)
            ? $definition[WorkflowDefinitionSchema::FIELD_NODES]
            : [];

        foreach ($definitionNodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $key = is_string($node[WorkflowDefinitionSchema::FIELD_KEY] ?? null)
                ? $node[WorkflowDefinitionSchema::FIELD_KEY]
                : '';

            if ($key === '') {
                continue;
            }

            $type = is_string($node[WorkflowDefinitionSchema::FIELD_TYPE] ?? null)
                ? $node[WorkflowDefinitionSchema::FIELD_TYPE]
                : 'unknown';

            $position = is_array($node[WorkflowDefinitionSchema::FIELD_POSITION] ?? null)
                ? $node[WorkflowDefinitionSchema::FIELD_POSITION]
                : [];

            $name = is_string($node[WorkflowDefinitionSchema::FIELD_NAME] ?? null)
                ? $node[WorkflowDefinitionSchema::FIELD_NAME]
                : $key;

            $properties = [
                'key' => $key,
                'name' => $name,
                'dbflow_type' => $type,
            ];

            if (array_key_exists(WorkflowDefinitionSchema::FIELD_CONFIG, $node)
                && is_array($node[WorkflowDefinitionSchema::FIELD_CONFIG])) {
                $properties['config'] = $this->deepCopy($node[WorkflowDefinitionSchema::FIELD_CONFIG]);
            }

            if (array_key_exists(WorkflowDefinitionSchema::FIELD_METADATA, $node)
                && is_array($node[WorkflowDefinitionSchema::FIELD_METADATA])) {
                $properties['metadata'] = $this->deepCopy($node[WorkflowDefinitionSchema::FIELD_METADATA]);
            }

            $nodes[] = [
                'id' => $key,
                'type' => $this->logicFlowTypeForDbflowType($type),
                'x' => $this->sanitizeCoordinate($position['x'] ?? 0),
                'y' => $this->sanitizeCoordinate($position['y'] ?? 0),
                'text' => $name,
                'properties' => $properties,
            ];
        }

        $edges = [];
        $transitions = is_array($definition[WorkflowDefinitionSchema::FIELD_TRANSITIONS] ?? null)
            ? $definition[WorkflowDefinitionSchema::FIELD_TRANSITIONS]
            : [];

        foreach ($transitions as $transition) {
            if (! is_array($transition)) {
                continue;
            }

            $from = is_string($transition[WorkflowDefinitionSchema::FIELD_FROM] ?? null)
                ? $transition[WorkflowDefinitionSchema::FIELD_FROM]
                : '';
            $to = is_string($transition[WorkflowDefinitionSchema::FIELD_TO] ?? null)
                ? $transition[WorkflowDefinitionSchema::FIELD_TO]
                : '';

            if ($from === '' || $to === '') {
                continue;
            }

            $condition = null;

            if (array_key_exists(WorkflowDefinitionSchema::FIELD_CONDITION, $transition)) {
                $rawCondition = $transition[WorkflowDefinitionSchema::FIELD_CONDITION];

                if (is_string($rawCondition) && $rawCondition !== '') {
                    $condition = $rawCondition;
                }
            }

            $priority = null;

            if (array_key_exists(WorkflowDefinitionSchema::FIELD_PRIORITY, $transition)) {
                $priority = $transition[WorkflowDefinitionSchema::FIELD_PRIORITY];
            }

            $isDefault = (bool) ($transition[WorkflowDefinitionSchema::FIELD_IS_DEFAULT] ?? false);

            $edges[] = [
                'id' => $this->edgeId($from, $to),
                'type' => 'polyline',
                'sourceNodeId' => $from,
                'targetNodeId' => $to,
                'properties' => [
                    'condition' => $condition,
                    'priority' => $priority,
                    'is_default' => $isDefault,
                ],
            ];
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * @param  array<string, mixed>  $baseDefinition
     * @param  array<string, mixed>  $graphData
     * @return array<string, mixed>
     */
    public function fromLogicFlow(
        array $baseDefinition,
        array $graphData,
    ): array {
        $nodes = is_array($graphData['nodes'] ?? null) ? $graphData['nodes'] : [];
        $edges = is_array($graphData['edges'] ?? null) ? $graphData['edges'] : [];

        $definition = [
            WorkflowDefinitionSchema::FIELD_KEY => is_string($baseDefinition[WorkflowDefinitionSchema::FIELD_KEY] ?? null)
                ? $baseDefinition[WorkflowDefinitionSchema::FIELD_KEY]
                : '',
            WorkflowDefinitionSchema::FIELD_NAME => is_string($baseDefinition[WorkflowDefinitionSchema::FIELD_NAME] ?? null)
                ? $baseDefinition[WorkflowDefinitionSchema::FIELD_NAME]
                : '',
            WorkflowDefinitionSchema::FIELD_NODES => $this->nodesFromLogicFlow($nodes),
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => $this->transitionsFromLogicFlow($edges),
        ];

        foreach ([
            WorkflowDefinitionSchema::FIELD_DESCRIPTION,
            WorkflowDefinitionSchema::FIELD_VERSION,
            WorkflowDefinitionSchema::FIELD_METADATA,
        ] as $field) {
            if (array_key_exists($field, $baseDefinition)) {
                $definition[$field] = $this->deepCopy($baseDefinition[$field]);
            }
        }

        return $definition;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function nodesFromLogicFlow(array $nodes): array
    {
        $dbflowNodes = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            $key = is_string($properties['key'] ?? null) && $properties['key'] !== ''
                ? $properties['key']
                : (is_string($node['id'] ?? null) ? $node['id'] : '');

            if ($key === '') {
                continue;
            }

            $type = $this->dbflowTypeFromLogicFlowNode($node, $properties);

            $dbflowNode = [
                WorkflowDefinitionSchema::FIELD_KEY => $key,
                WorkflowDefinitionSchema::FIELD_TYPE => $type,
                WorkflowDefinitionSchema::FIELD_NAME => is_string($properties['name'] ?? null) && $properties['name'] !== ''
                    ? $properties['name']
                    : (is_string($node['text'] ?? null) && $node['text'] !== '' ? $node['text'] : $key),
                WorkflowDefinitionSchema::FIELD_POSITION => [
                    'x' => $this->sanitizeCoordinate($node['x'] ?? 0),
                    'y' => $this->sanitizeCoordinate($node['y'] ?? 0),
                ],
            ];

            if (array_key_exists('config', $properties) && is_array($properties['config'])) {
                $dbflowNode[WorkflowDefinitionSchema::FIELD_CONFIG] = $this->deepCopy($properties['config']);
            }

            if (array_key_exists('metadata', $properties) && is_array($properties['metadata'])) {
                $dbflowNode[WorkflowDefinitionSchema::FIELD_METADATA] = $this->deepCopy($properties['metadata']);
            }

            $dbflowNodes[] = $dbflowNode;
        }

        return $dbflowNodes;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $properties
     */
    private function dbflowTypeFromLogicFlowNode(array $node, array $properties): string
    {
        $logicFlowType = is_string($node['type'] ?? null) ? $node['type'] : '';
        $mappedType = $this->dbflowTypeForLogicFlowType($logicFlowType);

        if ($mappedType !== 'unknown') {
            return $mappedType;
        }

        if (is_string($properties['dbflow_type'] ?? null) && $properties['dbflow_type'] !== '') {
            return $properties['dbflow_type'];
        }

        return 'unknown';
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function transitionsFromLogicFlow(array $edges): array
    {
        $transitions = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $from = is_string($edge['sourceNodeId'] ?? null) ? $edge['sourceNodeId'] : '';
            $to = is_string($edge['targetNodeId'] ?? null) ? $edge['targetNodeId'] : '';

            if ($from === '' || $to === '') {
                continue;
            }

            $properties = is_array($edge['properties'] ?? null) ? $edge['properties'] : [];

            $transition = [
                WorkflowDefinitionSchema::FIELD_FROM => $from,
                WorkflowDefinitionSchema::FIELD_TO => $to,
            ];

            if (array_key_exists('condition', $properties)) {
                $condition = $properties['condition'];

                if (is_string($condition) && $condition !== '') {
                    $transition[WorkflowDefinitionSchema::FIELD_CONDITION] = $condition;
                }
            }

            if (array_key_exists('priority', $properties) && $properties['priority'] !== null) {
                $transition[WorkflowDefinitionSchema::FIELD_PRIORITY] = $properties['priority'];
            }

            if ((bool) ($properties['is_default'] ?? false)) {
                $transition[WorkflowDefinitionSchema::FIELD_IS_DEFAULT] = true;
            }

            $transitions[] = $transition;
        }

        return $transitions;
    }

    private function logicFlowTypeForDbflowType(string $type): string
    {
        return self::TYPE_MAP[$type] ?? 'dbflow-unknown';
    }

    private function dbflowTypeForLogicFlowType(string $type): string
    {
        foreach (self::TYPE_MAP as $dbflowType => $logicFlowType) {
            if ($logicFlowType === $type) {
                return $dbflowType;
            }
        }

        return 'unknown';
    }

    private function edgeId(string $from, string $to): string
    {
        return $from.'--'.$to;
    }

    private function sanitizeCoordinate(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value)) {
            return max(0, (int) round($value));
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) round((float) $value));
        }

        return 0;
    }

    /**
     * @return array<mixed>|scalar|null
     */
    private function deepCopy(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $copy = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true);

        return is_array($copy) ? $copy : [];
    }
}
