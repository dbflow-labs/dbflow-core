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

namespace DbflowLabs\Core\Presenters;

use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class WorkflowInstanceCanvasPresenter
{
    /**
     * Log events treated as engine "passed through" (evidence that a node completed advancement).
     *
     * @var list<string>
     */
    private const PASS_THROUGH_EVENTS = [
        WorkflowLogEvent::TaskApproved->value,
        WorkflowLogEvent::ActionExecuted->value,
        WorkflowLogEvent::WorkflowStarted->value,
        WorkflowLogEvent::WorkflowCompleted->value,
    ];

    /**
     * Return a canvas tracking graph with runtime status.
     *
     * Executes only 3 SQL queries (instance + tasks + logs); all status computation is done in memory.
     *
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     transitions: list<array<string, mixed>>,
     *     instance_status: string,
     *     current_node_key: string|null,
     * }
     *
     * @throws ModelNotFoundException When the instance does not exist
     */
    public function getTrackingGraph(int $instanceId): array
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()
            ->with(['workflowVersion', 'tasks', 'logs'])
            ->findOrFail($instanceId);

        $definition = $instance->workflowVersion?->definition() ?? [];

        $rawNodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];
        $rawTransitions = is_array($definition['transitions'] ?? null) ? $definition['transitions'] : [];

        // Build an O(1) lookup table keyed by node_key for all tasks.
        // The same node_key may appear across multiple iterations; use the latest (highest id) as representative status.
        /** @var Collection<string, WorkflowTask> $taskByNodeKey */
        $taskByNodeKey = $instance->tasks
            ->sortByDesc('id')
            ->keyBy('node_key');

        // Extract the set of node keys the engine has "passed through" from logs.
        // Evidence: task_approved / action_executed / workflow_started / workflow_completed events
        // linked to a node_key (via the task's node_key or payload['node_key']).
        $passedNodeKeys = $this->resolvePassedNodeKeys($instance);

        $nodes = $this->hydrateNodes($rawNodes, $taskByNodeKey, $passedNodeKeys);
        $transitions = $this->hydrateTransitions($rawTransitions, $passedNodeKeys);

        return [
            'nodes' => $nodes,
            'transitions' => $transitions,
            'instance_status' => $instance->status->value,
            'current_node_key' => $instance->current_node_key,
        ];
    }

    /**
     * Inject runtime_status into the node array.
     *
     * Status matrix (highest priority first):
     * - task.status = approved / skipped â†?'approved'
     * - task.status = rejected / cancelled â†?'rejected'
     * - task.status = pending             â†?'running' (current active bottleneck)
     * - Logs prove this node passed but no task (start / end / action nodes) â†?'approved'
     * - No records at all                        â†?'pending' (not yet reached)
     *
     * @param  list<array<string, mixed>>  $rawNodes
     * @param  Collection<string, WorkflowTask>  $taskByNodeKey
     * @param  array<string, true>  $passedNodeKeys
     * @return list<array<string, mixed>>
     */
    private function hydrateNodes(array $rawNodes, Collection $taskByNodeKey, array $passedNodeKeys): array
    {
        $result = [];

        foreach ($rawNodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeKey = is_string($node['key'] ?? null) ? $node['key'] : '';
            $node['runtime_status'] = $this->resolveNodeRuntimeStatus($nodeKey, $taskByNodeKey, $passedNodeKeys);

            $result[] = $node;
        }

        return $result;
    }

    /**
     * @param  Collection<string, WorkflowTask>  $taskByNodeKey
     * @param  array<string, true>  $passedNodeKeys
     */
    private function resolveNodeRuntimeStatus(
        string $nodeKey,
        Collection $taskByNodeKey,
        array $passedNodeKeys,
    ): string {
        $task = $nodeKey !== '' ? $taskByNodeKey->get($nodeKey) : null;

        if ($task instanceof WorkflowTask) {
            return match ($task->status) {
                WorkflowTaskStatus::Approved, WorkflowTaskStatus::Skipped => 'approved',
                WorkflowTaskStatus::Rejected, WorkflowTaskStatus::Cancelled => 'rejected',
                WorkflowTaskStatus::Pending => 'running',
            };
        }

        // Nodes without approval tasks (start / end / action): mark approved if logs prove passage
        if ($nodeKey !== '' && isset($passedNodeKeys[$nodeKey])) {
            return 'approved';
        }

        return 'pending';
    }

    /**
     * Inject runtime_status into the transition array.
     *
     * Criterion: an edge fromâ†’to is marked 'passed' only when the engine passed through from **and** to has been reached;
     * otherwise 'idle'.
     *
     * This is stricter than checking from alone: avoids marking unselected fork branches as passed.
     *
     * @param  list<array<string, mixed>>  $rawTransitions
     * @param  array<string, true>  $passedNodeKeys
     * @return list<array<string, mixed>>
     */
    private function hydrateTransitions(array $rawTransitions, array $passedNodeKeys): array
    {
        $result = [];

        foreach ($rawTransitions as $transition) {
            if (! is_array($transition)) {
                continue;
            }

            $from = is_string($transition['from'] ?? null) ? $transition['from'] : '';
            $to = is_string($transition['to'] ?? null) ? $transition['to'] : '';

            $passed = $from !== ''
                && $to !== ''
                && isset($passedNodeKeys[$from])
                && isset($passedNodeKeys[$to]);

            $transition['runtime_status'] = $passed ? 'passed' : 'idle';

            $result[] = $transition;
        }

        return $result;
    }

    /**
     * Derive the set of node keys the engine actually traversed from logs.
     *
     * Source 1: task_approved / task_rejected / task_cancelled logs â†?node_key via associated task
     * Source 2: action_executed / workflow_started / workflow_completed logs â†?payload['node_key']
     * Source 3: instance.current_node_key (for running instances, start nodes typically have no task)
     *
     * @return array<string, true> Keys are node_key; values are always true for existence lookup only
     */
    private function resolvePassedNodeKeys(WorkflowInstance $instance): array
    {
        $passed = [];

        // Source 1: nodes with tasks that have finished (approved / rejected / cancelled / skipped)
        foreach ($instance->tasks as $task) {
            if (! $task instanceof WorkflowTask) {
                continue;
            }

            $key = is_string($task->node_key) && $task->node_key !== '' ? $task->node_key : null;

            if ($key === null) {
                continue;
            }

            // Pending tasks represent the current active node, not "passed through"
            if ($task->status !== WorkflowTaskStatus::Pending) {
                $passed[$key] = true;
            }
        }

        // Source 2: nodes recorded in engine advancement events (start / end / action nodes have no tasks)
        foreach ($instance->logs as $log) {
            $eventValue = $log->event instanceof WorkflowLogEvent
                ? $log->event->value
                : (is_string($log->event) ? $log->event : '');

            if (! in_array($eventValue, self::PASS_THROUGH_EVENTS, true)) {
                continue;
            }

            $payload = is_array($log->payload) ? $log->payload : [];
            $nodeKey = is_string($payload['node_key'] ?? null) && $payload['node_key'] !== ''
                ? $payload['node_key']
                : null;

            if ($nodeKey !== null) {
                $passed[$nodeKey] = true;
            }
        }

        // Source 3: workflow_started means the start node has executed.
        // Using current_node_key as fallback is unreliable (it is the current node, not necessarily passed).
        // The start node key cannot be read directly from logs, but workflow_started payload contains workflow_key;
        // therefore mark the start node separately: once the workflow has started, the start node is always passed.
        $startNodeKey = $this->findStartNodeKey($instance);

        if ($startNodeKey !== null) {
            $passed[$startNodeKey] = true;
        }

        return $passed;
    }

    /**
     * Find the start node key from the version definition.
     * Start nodes have no task and no payload node_key; read from definition instead.
     */
    private function findStartNodeKey(WorkflowInstance $instance): ?string
    {
        $definition = $instance->workflowVersion?->definition() ?? [];
        $nodes = is_array($definition['nodes'] ?? null) ? $definition['nodes'] : [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'start') {
                $key = $node['key'] ?? null;

                return is_string($key) && $key !== '' ? $key : null;
            }
        }

        return null;
    }
}
