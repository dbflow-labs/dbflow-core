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

namespace DbflowLabs\Core\Definitions;

use DbflowLabs\Core\Definitions\Contracts\WorkflowNodeInterface;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\Nodes\ConditionNode;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Definitions\Nodes\StartNode;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use InvalidArgumentException;

final class Blueprint
{
    /**
     * @var list<WorkflowNodeInterface>
     */
    private array $nodes = [];

    /**
     * @var list<Transition>
     */
    private array $transitions = [];

    /**
     * Opaque UI payload for blueprint-level canvas state.
     * Never consumed by the workflow execution engine.
     *
     * @var array<string, mixed>
     */
    protected array $metadata = [];

    /**
     * @param  list<WorkflowNodeInterface>  $nodes
     * @param  list<Transition>  $transitions
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        private readonly string $key,
        private readonly string $name,
        array $nodes = [],
        array $transitions = [],
        private readonly string $schemaVersion = '1.0',
        private readonly ?string $description = null,
        array $metadata = [],
    ) {
        foreach ($nodes as $node) {
            $this->addNode($node);
        }

        foreach ($transitions as $transition) {
            $this->addTransition($transition);
        }

        $this->metadata = $metadata;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function schemaVersion(): string
    {
        return $this->schemaVersion;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<WorkflowNodeInterface>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<Transition>
     */
    public function transitions(): array
    {
        return $this->transitions;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function addNode(WorkflowNodeInterface $node): void
    {
        foreach ($this->nodes as $existingNode) {
            if ($existingNode->key() === $node->key()) {
                throw new InvalidArgumentException("Duplicate node key [{$node->key()}].");
            }
        }

        $this->nodes[] = $node;
    }

    public function addTransition(Transition $transition): void
    {
        $this->transitions[] = $transition;
    }

    public function findNode(string $key): ?WorkflowNodeInterface
    {
        foreach ($this->nodes as $node) {
            if ($node->key() === $key) {
                return $node;
            }
        }

        return null;
    }

    public function findStartNode(): StartNode
    {
        foreach ($this->nodes as $node) {
            if ($node instanceof StartNode) {
                return $node;
            }
        }

        throw new InvalidWorkflowDefinitionException('Workflow definition is missing start node.');
    }

    /**
     * @return list<Transition>
     */
    public function transitionsFrom(string $nodeKey): array
    {
        $matches = [];

        foreach ($this->transitions as $transition) {
            if ($transition->from() === $nodeKey) {
                $matches[] = $transition;
            }
        }

        return $matches;
    }

    /**
     * @return list<Transition>
     */
    public function transitionsTo(string $nodeKey): array
    {
        $matches = [];

        foreach ($this->transitions as $transition) {
            if ($transition->to() === $nodeKey) {
                $matches[] = $transition;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $key = is_string($data[WorkflowDefinitionSchema::FIELD_KEY] ?? null)
            ? $data[WorkflowDefinitionSchema::FIELD_KEY]
            : '';

        if ($key === '') {
            throw new InvalidArgumentException('Blueprint key is required.');
        }

        $name = is_string($data[WorkflowDefinitionSchema::FIELD_NAME] ?? null)
            ? $data[WorkflowDefinitionSchema::FIELD_NAME]
            : '';

        if ($name === '') {
            throw new InvalidArgumentException('Blueprint name is required.');
        }

        $schemaVersion = is_string($data[WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION] ?? null)
            && $data[WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION] !== ''
            ? $data[WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION]
            : '1.0';

        $description = $data[WorkflowDefinitionSchema::FIELD_DESCRIPTION] ?? null;
        $description = is_string($description) && $description !== '' ? $description : null;

        $metadata = $data[WorkflowDefinitionSchema::FIELD_METADATA] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];

        $rawNodes = $data[WorkflowDefinitionSchema::FIELD_NODES] ?? [];
        $nodes = [];

        if (is_array($rawNodes)) {
            foreach ($rawNodes as $rawNode) {
                if (! is_array($rawNode)) {
                    continue;
                }

                $nodes[] = self::nodeFromArray($rawNode);
            }
        }

        $rawTransitions = $data[WorkflowDefinitionSchema::FIELD_TRANSITIONS] ?? [];
        $transitions = [];

        if (is_array($rawTransitions)) {
            foreach ($rawTransitions as $rawTransition) {
                if (! is_array($rawTransition)) {
                    continue;
                }

                $transitions[] = Transition::fromArray($rawTransition);
            }
        }

        return new self(
            key: $key,
            name: $name,
            nodes: $nodes,
            transitions: $transitions,
            schemaVersion: $schemaVersion,
            description: $description,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nodeFromArray(array $data): WorkflowNodeInterface
    {
        $type = is_string($data[WorkflowDefinitionSchema::FIELD_TYPE] ?? null)
            ? $data[WorkflowDefinitionSchema::FIELD_TYPE]
            : '';

        return match ($type) {
            WorkflowDefinitionSchema::NODE_TYPE_START => StartNode::fromArray($data),
            WorkflowDefinitionSchema::NODE_TYPE_APPROVAL => ApprovalNode::fromArray($data),
            WorkflowDefinitionSchema::NODE_TYPE_CONDITION => ConditionNode::fromArray($data),
            WorkflowDefinitionSchema::NODE_TYPE_ACTION => ActionNode::fromArray($data),
            WorkflowDefinitionSchema::NODE_TYPE_END => EndNode::fromArray($data),
            default => throw new InvalidArgumentException("Unsupported node type [{$type}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            WorkflowDefinitionSchema::FIELD_KEY => $this->key,
            WorkflowDefinitionSchema::FIELD_NAME => $this->name,
            WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION => $this->schemaVersion,
            WorkflowDefinitionSchema::FIELD_NODES => array_map(
                static fn (WorkflowNodeInterface $node): array => $node->toArray(),
                $this->nodes,
            ),
            WorkflowDefinitionSchema::FIELD_TRANSITIONS => array_map(
                static fn (Transition $transition): array => $transition->toArray(),
                $this->transitions,
            ),
        ];

        if ($this->description !== null) {
            $payload[WorkflowDefinitionSchema::FIELD_DESCRIPTION] = $this->description;
        }

        if ($this->metadata !== []) {
            $payload[WorkflowDefinitionSchema::FIELD_METADATA] = $this->metadata;
        }

        return $payload;
    }
}
