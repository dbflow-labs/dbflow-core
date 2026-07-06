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

namespace DbflowLabs\Core\Validation;

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\Nodes\ConditionNode;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Definitions\Nodes\StartNode;
use DbflowLabs\Core\Definitions\Transition;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\TimeoutOnTimeout;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Exceptions\InvalidWorkflowTopologyException;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Validation\Topology\CycleDetector;
use DbflowLabs\Core\Validation\Topology\IsolatedNodeDetector;
use DbflowLabs\Core\Validation\Topology\ReachabilityAnalyzer;
use DbflowLabs\Core\Validation\Topology\TerminationAnalyzer;
use DbflowLabs\Core\Validation\Topology\WorkflowGraph;
use DbflowLabs\Core\Support\TimeoutDueAtResolver;
use InvalidArgumentException;

final class BlueprintValidator
{
    /**
     * @var list<array{path: string, code: string, message: string}>
     */
    private array $errors = [];

    /**
     * @var list<array{path: string, code: string, message: string}>
     */
    private array $warnings = [];

    private bool $strict = true;

    private ?Blueprint $currentBlueprint = null;

    public function __construct(
        private readonly CycleDetector $cycleDetector = new CycleDetector,
        private readonly IsolatedNodeDetector $isolatedNodeDetector = new IsolatedNodeDetector,
        private readonly ReachabilityAnalyzer $reachabilityAnalyzer = new ReachabilityAnalyzer,
        private readonly TerminationAnalyzer $terminationAnalyzer = new TerminationAnalyzer,
        private readonly ?AssigneeResolverRegistry $assigneeResolverRegistry = null,
    ) {}

    public function validate(Blueprint $blueprint, bool $strict = true): WorkflowDefinitionValidationResult
    {
        $this->errors = [];
        $this->warnings = [];
        $this->strict = $strict;
        $this->currentBlueprint = $blueprint;

        $this->validateBlueprintMeta($blueprint);

        if ($blueprint->nodes() === []) {
            if ($this->strict) {
                $this->addError('nodes', 'required', 'Workflow nodes are required and must be a non-empty array.');
            } else {
                $this->addWarning('nodes', 'required', 'Workflow nodes are required and must be a non-empty array.');
            }

            return $this->result();
        }

        $nodeIndexByKey = $this->validateNodes($blueprint);

        if ($this->errors !== []) {
            return $this->result();
        }

        $graph = WorkflowGraph::fromBlueprint($blueprint);

        $this->validateTransitions($graph, $nodeIndexByKey);
        $this->validateTopology($graph);

        return $this->result();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function validateArray(array $definition, bool $strict = true): WorkflowDefinitionValidationResult
    {
        $this->errors = [];
        $this->warnings = [];
        $this->strict = $strict;

        $this->validateTopLevelArray($definition);

        if ($this->errors !== []) {
            return $this->result();
        }

        $nodes = $definition[WorkflowDefinitionSchema::FIELD_NODES] ?? [];

        if (! is_array($nodes) || $nodes === []) {
            if ($this->strict) {
                $this->addError('nodes', 'required', 'Workflow nodes are required and must be a non-empty array.');
            } else {
                $this->addWarning('nodes', 'required', 'Workflow nodes are required and must be a non-empty array.');
            }

            return $this->result();
        }

        try {
            $blueprint = Blueprint::fromArray($definition);
        } catch (InvalidArgumentException $exception) {
            $this->addError('nodes', 'invalid_structure', $exception->getMessage());

            return $this->result();
        }

        return $this->validate($blueprint, $strict);
    }

    public function validateOrFail(Blueprint|array $input, bool $strict = true): void
    {
        $result = $input instanceof Blueprint
            ? $this->validate($input, $strict)
            : $this->validateArray($input, $strict);

        if ($result->isValid()) {
            return;
        }

        $firstError = $result->errors()[0] ?? null;
        $topologyCodes = [
            'cycle_detected',
            'isolated_node',
            'dead_end',
            'condition_branch_dead_end',
        ];

        if ($firstError !== null && in_array($firstError['code'], $topologyCodes, true)) {
            throw new InvalidWorkflowTopologyException(
                violationCode: $firstError['code'],
                message: $firstError['message'],
                nodeKey: $this->extractNodeKeyFromPath($firstError['path']),
                validationResult: $result,
            );
        }

        throw new InvalidWorkflowDefinitionException($result);
    }

    private function validateBlueprintMeta(Blueprint $blueprint): void
    {
        if (! preg_match(WorkflowDefinitionSchema::KEY_PATTERN, $blueprint->key())) {
            $this->addError('key', 'invalid_format', 'Workflow key must match /^[a-z0-9_]+$/ pattern.');
        }

        if ($blueprint->name() === '') {
            $this->addError('name', 'required', 'Workflow name is required.');
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function validateTopLevelArray(array $definition): void
    {
        $key = $definition[WorkflowDefinitionSchema::FIELD_KEY] ?? null;

        if (! is_string($key) || $key === '') {
            $this->addError('key', 'required', 'Workflow key is required.');
        } elseif (! preg_match(WorkflowDefinitionSchema::KEY_PATTERN, $key)) {
            $this->addError('key', 'invalid_format', 'Workflow key must match /^[a-z0-9_]+$/ pattern.');
        }

        $name = $definition[WorkflowDefinitionSchema::FIELD_NAME] ?? null;

        if (! is_string($name) || $name === '') {
            $this->addError('name', 'required', 'Workflow name is required.');
        }

        $transitions = $definition[WorkflowDefinitionSchema::FIELD_TRANSITIONS] ?? null;

        if (! is_array($transitions)) {
            $this->addError('transitions', 'required', 'Workflow transitions are required and must be an array.');
        }
    }

    /**
     * @return array<string, int>
     */
    private function validateNodes(Blueprint $blueprint): array
    {
        $nodeIndexByKey = [];
        $startNodeCount = 0;
        $endNodeCount = 0;

        foreach ($blueprint->nodes() as $index => $node) {
            $nodePath = 'nodes.'.$index;
            $nodeKey = $node->key();

            if (! preg_match(WorkflowDefinitionSchema::KEY_PATTERN, $nodeKey)) {
                $this->addError($nodePath.'.key', 'invalid_format', 'Node key must match /^[a-z0-9_]+$/ pattern.');
            }

            if ($node->name() === '') {
                $this->addError($nodePath.'.name', 'required', 'Node name is required.');
            }

            $nodeIndexByKey[$nodeKey] = $index;

            if ($node instanceof StartNode) {
                $startNodeCount++;
            }

            if ($node instanceof EndNode) {
                $endNodeCount++;
            }

            if ($node instanceof ApprovalNode) {
                $this->validateApprovalNode($nodePath, $node);
            }

            if ($node instanceof ConditionNode) {
                $this->validateConditionNode($nodePath, $node);
            }

            if ($node instanceof EndNode) {
                $this->validateEndNode($nodePath, $node);
            }
        }

        if ($startNodeCount === 0) {
            $this->addError('nodes', 'missing_start', 'Workflow definition must have exactly one start node.');
        } elseif ($startNodeCount > 1) {
            $this->addError('nodes', 'multiple_start', 'Workflow definition must have exactly one start node.');
        }

        if ($endNodeCount < 1) {
            $this->addError('nodes', 'missing_end', 'Workflow definition must have at least one end node.');
        }

        return $nodeIndexByKey;
    }

    private function validateApprovalNode(string $nodePath, ApprovalNode $node): void
    {
        $this->validateApprovalNodeTimeout($nodePath, $node);

        $assignees = $node->assignees();
        $assigneeType = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_TYPE] ?? null;

        if ($assigneeType === null || ! is_string($assigneeType)) {
            $this->addError($nodePath.'.config.assignees.type', 'required', 'Approval node assignees.type is required.');

            return;
        }

        if (! in_array($assigneeType, WorkflowDefinitionSchema::assigneeTypes(), true)) {
            $this->addError(
                $nodePath.'.config.assignees.type',
                'invalid_value',
                'Approval node assignees.type must be one of: user, role, permission, callback.',
            );

            return;
        }

        if (! in_array($assigneeType, WorkflowDefinitionSchema::runtimeSupportedAssigneeTypes(), true)) {
            $this->addError(
                $nodePath.'.config.assignees.type',
                'unsupported_assignee_type',
                "Assignee type [{$assigneeType}] is not supported by the open-core runtime. Use user assignees or register a callback/permission resolver.",
            );

            return;
        }

        if (! in_array($node->approvalMode(), [ApprovalMode::Any, ApprovalMode::All, ApprovalMode::Sequential], true)) {
            $this->addError(
                $nodePath.'.config.approval_mode',
                'invalid_value',
                'Approval node approval_mode must be one of: any, all, sequential.',
            );
        }

        if ($assigneeType === WorkflowDefinitionSchema::ASSIGNEE_TYPE_CALLBACK) {
            $callback = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_CALLBACK] ?? null;
            $value = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE] ?? null;

            if ((! is_string($callback) || $callback === '') && (! is_string($value) || $value === '')) {
                $this->addAssigneeValueViolation(
                    $nodePath.'.config.assignees.value',
                    'Approval node assignees callback or value is required for callback assignee type.',
                );
            } else {
                $this->assertAssigneeResolverRegistered(
                    $nodePath.'.config.assignees',
                    is_string($callback) && $callback !== '' ? $callback : (string) $value,
                );
            }

            return;
        }

        $value = $assignees[WorkflowDefinitionSchema::ASSIGNEE_FIELD_VALUE] ?? null;

        if ($value === null || $value === '') {
            $this->addAssigneeValueViolation(
                $nodePath.'.config.assignees.value',
                'Approval node assignees.value is required.',
            );

            return;
        }

        if ($assigneeType === WorkflowDefinitionSchema::ASSIGNEE_TYPE_PERMISSION) {
            $this->assertAssigneeResolverRegistered($nodePath.'.config.assignees.value', (string) $value);

            return;
        }

        if ($assigneeType === WorkflowDefinitionSchema::ASSIGNEE_TYPE_USER
            && ! WorkflowDefinitionSchema::isValidUserAssigneeValue($value)) {
            $this->addError(
                $nodePath.'.config.assignees.value',
                'invalid_value',
                'User assignee value must be a positive integer user id.',
            );
        }
    }

    private function validateApprovalNodeTimeout(string $nodePath, ApprovalNode $node): void
    {
        $dueIn = $node->timeoutDueIn();
        $onTimeout = $node->timeoutOnTimeout();
        $onTimeoutRaw = null;

        if ($this->currentBlueprint !== null) {
            $definitionNode = $this->findDefinitionNode($node->key());
            $config = is_array($definitionNode['config'] ?? null) ? $definitionNode['config'] : [];
            $timeout = is_array($config[WorkflowDefinitionSchema::CONFIG_TIMEOUT] ?? null)
                ? $config[WorkflowDefinitionSchema::CONFIG_TIMEOUT]
                : [];
            $raw = $timeout[WorkflowDefinitionSchema::TIMEOUT_ON_TIMEOUT] ?? null;
            $onTimeoutRaw = is_string($raw) ? $raw : null;
        }

        if ($dueIn === null && $onTimeout === null && ($onTimeoutRaw === null || $onTimeoutRaw === '')) {
            return;
        }

        if ($dueIn === null || $dueIn === '') {
            $this->addError(
                $nodePath.'.config.timeout.due_in',
                'required',
                'Approval node timeout.due_in is required when timeout is configured.',
            );

            return;
        }

        if (! TimeoutDueAtResolver::isValidDuration($dueIn)) {
            $this->addError(
                $nodePath.'.config.timeout.due_in',
                'invalid_value',
                'Approval node timeout.due_in must be a positive ISO 8601 duration (for example P1D or PT24H).',
            );
        }

        if ($onTimeoutRaw !== null && $onTimeoutRaw !== '' && ! $onTimeout instanceof TimeoutOnTimeout) {
            $this->addError(
                $nodePath.'.config.timeout.on_timeout',
                'invalid_value',
                'Approval node timeout.on_timeout must be reject_end when provided.',
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDefinitionNode(string $nodeKey): ?array
    {
        if ($this->currentBlueprint === null) {
            return null;
        }

        foreach ($this->currentBlueprint->toArray()[WorkflowDefinitionSchema::FIELD_NODES] ?? [] as $node) {
            if (is_array($node) && ($node[WorkflowDefinitionSchema::FIELD_KEY] ?? null) === $nodeKey) {
                return $node;
            }
        }

        return null;
    }

    private function assertAssigneeResolverRegistered(string $path, string $resolverKey): void
    {
        if ($this->assigneeResolverRegistry === null) {
            return;
        }

        if ($this->assigneeResolverRegistry->hasResolver($resolverKey)) {
            return;
        }

        $this->addError(
            $path,
            'missing_assignee_resolver',
            "Assignee resolver [{$resolverKey}] is not registered.",
        );
    }

    private function validateConditionNode(string $nodePath, ConditionNode $node): void
    {
        // Condition routing predicates live on outgoing transitions (see validateConditionRoutingContract).
    }

    private function validateEndNode(string $nodePath, EndNode $node): void
    {
        $status = $node->status();

        if ($status === null) {
            return;
        }

        if ($status === '') {
            $this->addError(
                $nodePath.'.config.status',
                'invalid_value',
                'End node status must be a non-empty string when provided.',
            );

            return;
        }

        if (! in_array($status, WorkflowDefinitionSchema::endNodeStatuses(), true)) {
            $this->addError(
                $nodePath.'.config.status',
                'invalid_value',
                'End node status must be one of: completed, approved, rejected, cancelled.',
            );
        }
    }

    /**
     * @param  array<string, int>  $nodeIndexByKey
     */
    private function validateTransitions(WorkflowGraph $graph, array $nodeIndexByKey): void
    {
        $transitionPairs = [];

        foreach ($graph->blueprint()->transitions() as $index => $transition) {
            $transitionPath = 'transitions.'.$index;
            $this->validateTransition($transition, $transitionPath, $nodeIndexByKey, $transitionPairs);
        }

        foreach ($graph->startNodeKeys() as $startNodeKey) {
            if ($graph->incomingCount($startNodeKey) > 0) {
                $this->addError(
                    'nodes.'.$nodeIndexByKey[$startNodeKey],
                    'invalid_incoming',
                    'Start node cannot have incoming transitions.',
                );
            }
        }

        foreach ($graph->endNodeKeys() as $endNodeKey) {
            if ($graph->outgoingCount($endNodeKey) > 0) {
                $this->addError(
                    'nodes.'.$nodeIndexByKey[$endNodeKey],
                    'invalid_outgoing',
                    'End node cannot have outgoing transitions.',
                );
            }
        }

        foreach ($graph->nodeTypeByKey() as $nodeKey => $nodeType) {
            if ($nodeType === WorkflowDefinitionSchema::NODE_TYPE_END) {
                continue;
            }

            if ($graph->outgoingCount($nodeKey) < 1) {
                $this->addError(
                    'nodes.'.$nodeIndexByKey[$nodeKey],
                    'missing_outgoing',
                    'Every non-end node must have at least one outgoing transition.',
                );
            }
        }

        foreach ($graph->nodeIndexByKey() as $nodeKey => $index) {
            if (($graph->nodeTypeByKey()[$nodeKey] ?? null) !== WorkflowDefinitionSchema::NODE_TYPE_CONDITION) {
                continue;
            }

            $outgoing = $graph->outgoingTransitions($nodeKey);

            $this->validateConditionRoutingContract($graph, $nodeKey, $index);

            if (count($outgoing) <= 1) {
                continue;
            }

            $hasDefault = false;
            $allHaveCondition = true;

            foreach ($outgoing as $outgoingTransition) {
                if ($outgoingTransition->isDefault()) {
                    $hasDefault = true;
                }

                $condition = $outgoingTransition->condition();

                if ($condition === null || trim($condition) === '') {
                    $allHaveCondition = false;
                }
            }

            if (! $hasDefault && ! $allHaveCondition) {
                $this->addError(
                    'nodes.'.$index.'.transitions',
                    'invalid_condition_branching',
                    'Condition node with multiple outgoing transitions must have a default transition or conditions on all transitions.',
                );
            }
        }
    }

    private function validateConditionRoutingContract(
        WorkflowGraph $graph,
        string $nodeKey,
        int $nodeIndex,
    ): void {
        $node = $graph->blueprint()->findNode($nodeKey);

        if (! $node instanceof ConditionNode) {
            return;
        }

        $outgoing = $graph->outgoingTransitions($nodeKey);
        $nodeExpression = trim($node->expression());
        $transitionConditionCount = 0;

        foreach ($outgoing as $outgoingTransition) {
            $condition = $outgoingTransition->condition();

            if ($condition !== null && trim($condition) !== '') {
                $transitionConditionCount++;
            }
        }

        if ($nodeExpression !== '' && $transitionConditionCount === 0) {
            $this->addError(
                'nodes.'.$nodeIndex.'.config.expression',
                'ambiguous_condition_routing',
                'Condition routing predicates must be defined on outgoing transitions. ConditionNode.expression alone is not evaluated at runtime.',
            );
        }

        if (count($outgoing) > 1 && $transitionConditionCount === 0) {
            $hasDefault = false;

            foreach ($outgoing as $outgoingTransition) {
                if ($outgoingTransition->isDefault()) {
                    $hasDefault = true;
                    break;
                }
            }

            if (! $hasDefault) {
                $this->addError(
                    'nodes.'.$nodeIndex.'.transitions',
                    'missing_transition_condition',
                    'Condition gateway nodes require transition.condition predicates on at least one outgoing edge.',
                );
            }
        }
    }

    /**
     * @param  array<string, int>  $nodeIndexByKey
     * @param  array<string, true>  $transitionPairs
     */
    private function validateTransition(
        Transition $transition,
        string $transitionPath,
        array $nodeIndexByKey,
        array &$transitionPairs,
    ): void {
        $from = $transition->from();
        $to = $transition->to();

        if ($from === '') {
            $this->addError($transitionPath.'.from', 'required', 'Transition from is required.');
        }

        if ($to === '') {
            $this->addError($transitionPath.'.to', 'required', 'Transition to is required.');
        }

        if ($from === '' || $to === '') {
            return;
        }

        if ($from === $to) {
            $this->addError($transitionPath, 'self_loop', 'Transition from and to cannot be the same node.');
        }

        if (! isset($nodeIndexByKey[$from])) {
            $this->addError($transitionPath.'.from', 'unknown_node', 'Transition from references a missing node.');
        }

        if (! isset($nodeIndexByKey[$to])) {
            $this->addError($transitionPath.'.to', 'unknown_node', 'Transition to references a missing node.');
        }

        if (! isset($nodeIndexByKey[$from]) || ! isset($nodeIndexByKey[$to])) {
            return;
        }

        $pairKey = $from.'->'.$to;

        if (isset($transitionPairs[$pairKey])) {
            $this->addError($transitionPath, 'duplicate', 'Duplicate transition from the same node to the same target is invalid.');
        } else {
            $transitionPairs[$pairKey] = true;
        }
    }

    private function validateTopology(WorkflowGraph $graph): void
    {
        foreach ($this->isolatedNodeDetector->detect($graph) as $violation) {
            $this->addError($violation['path'], $violation['code'], $violation['message']);
        }

        foreach ($this->cycleDetector->detect($graph) as $violation) {
            $this->addError($violation['path'], $violation['code'], $violation['message']);
        }

        foreach ($this->reachabilityAnalyzer->unreachableFromStart($graph) as $violation) {
            $this->addError($violation['path'], $violation['code'], $violation['message']);
        }

        foreach ($this->terminationAnalyzer->analyze($graph) as $violation) {
            $this->addError($violation['path'], $violation['code'], $violation['message']);
        }
    }

    private function addAssigneeValueViolation(string $path, string $message): void
    {
        if ($this->strict) {
            $this->addError($path, 'required', $message);
        } else {
            $this->addWarning($path, 'required', $message);
        }
    }

    private function addError(string $path, string $code, string $message): void
    {
        $this->errors[] = [
            'path' => $path,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function addWarning(string $path, string $code, string $message): void
    {
        $this->warnings[] = [
            'path' => $path,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function result(): WorkflowDefinitionValidationResult
    {
        if ($this->errors !== []) {
            return WorkflowDefinitionValidationResult::invalid($this->errors, $this->warnings);
        }

        return WorkflowDefinitionValidationResult::valid($this->warnings);
    }

    private function extractNodeKeyFromPath(string $path): string
    {
        if ($this->currentBlueprint === null || ! preg_match('/^nodes\.(\d+)/', $path, $matches)) {
            return '';
        }

        $index = (int) $matches[1];
        $nodes = $this->currentBlueprint->nodes();

        return $nodes[$index]->key() ?? '';
    }
}
