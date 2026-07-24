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

use DbflowLabs\Core\Capabilities\RuntimeCapabilityRegistry;
use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\DefinitionSchemaVersion;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\Nodes\ConditionNode;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Definitions\Nodes\StartNode;
use DbflowLabs\Core\Definitions\Transition;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Enums\ApprovalMode;
use DbflowLabs\Core\Enums\ContextDataSource;
use DbflowLabs\Core\Enums\RuntimeCapability;
use DbflowLabs\Core\Enums\TimeoutOnTimeout;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Exceptions\InvalidWorkflowTopologyException;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;
use DbflowLabs\Core\Services\Actions\ReliableActionHandlerRegistry;
use DbflowLabs\Core\Sla\SlaDuration;
use DbflowLabs\Core\Sla\SlaPolicy;
use DbflowLabs\Core\Support\TimeoutDueAtResolver;
use DbflowLabs\Core\Validation\Topology\CycleDetector;
use DbflowLabs\Core\Validation\Topology\IsolatedNodeDetector;
use DbflowLabs\Core\Validation\Topology\ReachabilityAnalyzer;
use DbflowLabs\Core\Validation\Topology\TerminationAnalyzer;
use DbflowLabs\Core\Validation\Topology\WorkflowGraph;
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
        private readonly ?RuntimeCapabilityRegistry $runtimeCapabilityRegistry = null,
        private readonly ?ReliableActionHandlerRegistry $reliableActionHandlerRegistry = null,
    ) {}

    public function validate(Blueprint $blueprint, bool $strict = true): WorkflowDefinitionValidationResult
    {
        $this->errors = [];
        $this->warnings = [];
        $this->strict = $strict;
        $this->currentBlueprint = $blueprint;

        $this->validateBlueprintMeta($blueprint);
        $this->validateSchemaVersion($blueprint->schemaVersion());

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
        $this->validateSchemaVersionValue($definition[WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION] ?? null);
        $this->validateRuntimeCapabilitiesFromDefinition($definition);
        $this->validateRawActionNodeConfigs($definition);

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
            $this->addMappedHydrationError($exception->getMessage());

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

            if ($node instanceof ActionNode) {
                $this->validateActionNode($nodePath, $node);
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
        $this->validateApprovalNodeSla($nodePath, $node);

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
                'User assignee value must be a valid user id (positive integer or non-empty string id).',
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

    private function validateApprovalNodeSla(string $nodePath, ApprovalNode $node): void
    {
        if (! $node->hasSla()) {
            return;
        }

        $this->requireCapability(
            RuntimeCapability::Sla,
            $nodePath.'.config.'.WorkflowDefinitionSchema::CONFIG_SLA,
            'SLA configuration requires the sla runtime capability.',
        );

        $slaConfig = $node->slaConfig();

        if ($slaConfig === null) {
            return;
        }

        try {
            SlaPolicy::fromConfigArray($slaConfig);
        } catch (\InvalidArgumentException $exception) {
            $this->addError(
                $nodePath.'.config.'.WorkflowDefinitionSchema::CONFIG_SLA,
                'invalid_value',
                $exception->getMessage(),
            );

            return;
        }

        if ($node->hasTimeout()) {
            $timeoutDueIn = $node->timeoutDueIn();
            $slaDueAfter = $slaConfig[WorkflowDefinitionSchema::SLA_DUE_AFTER] ?? null;

            $equivalent = is_string($timeoutDueIn)
                && is_string($slaDueAfter)
                && $node->timeoutOnTimeout() === null
                && SlaDuration::isValid($timeoutDueIn)
                && SlaDuration::isValid($slaDueAfter)
                && SlaDuration::parse($timeoutDueIn)->totalSeconds() === SlaDuration::parse($slaDueAfter)->totalSeconds();

            if (! $equivalent) {
                $this->addError(
                    $nodePath.'.config',
                    'conflicting_timeout_and_sla',
                    'Approval node cannot define conflicting timeout and SLA policies.',
                );
            }
        }

        $overdue = is_array($slaConfig[WorkflowDefinitionSchema::SLA_OVERDUE] ?? null)
            ? $slaConfig[WorkflowDefinitionSchema::SLA_OVERDUE]
            : [];

        if (($overdue['approve'] ?? false) === true || ($overdue['auto_approve'] ?? false) === true) {
            $this->addError(
                $nodePath.'.config.'.WorkflowDefinitionSchema::CONFIG_SLA.'.overdue',
                'unsupported_value',
                'Automatic approval on SLA overdue is not supported.',
            );
        }

        if (($overdue['reject'] ?? false) === true || ($overdue['auto_reject'] ?? false) === true) {
            $this->addError(
                $nodePath.'.config.'.WorkflowDefinitionSchema::CONFIG_SLA.'.overdue',
                'unsupported_value',
                'Automatic rejection on SLA overdue is not supported.',
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

    private function validateActionNode(string $nodePath, ActionNode $node): void
    {
        $mode = $node->executionMode();

        if ($mode->isReliable()) {
            $this->requireCapability(
                RuntimeCapability::ReliableAction,
                $nodePath.'.config.execution_mode',
                "Action execution_mode [{$mode->value}] requires the reliable_action runtime capability.",
            );
        }

        if ($mode->isReliable() && $node->stopOnError()) {
            $this->addError(
                $nodePath.'.config.stop_on_error',
                'invalid_value',
                'stop_on_error cannot be combined with reliable execution modes.',
            );
        }

        if ($mode->isReliable()) {
            $actionKey = $node->actionKey();

            if ($actionKey === '') {
                $this->addError(
                    $nodePath.'.config.action_key',
                    'required',
                    'Reliable action nodes require a non-empty action_key.',
                );
            } elseif ($this->reliableActionHandlerRegistry !== null && ! $this->reliableActionHandlerRegistry->has($actionKey)) {
                $this->addError(
                    $nodePath.'.config.action_key',
                    'missing_reliable_action_handler',
                    "Reliable action handler [{$actionKey}] is not registered.",
                );
            }

            if ($actionKey === 'outbound_webhook') {
                $this->requireCapability(
                    RuntimeCapability::OutboundWebhook,
                    $nodePath.'.config.action_key',
                    'Action outbound_webhook requires the outbound_webhook runtime capability.',
                );
            }
        }

        if ($node->maxAttempts() !== null && $node->maxAttempts() < 1) {
            $this->addError(
                $nodePath.'.config.max_attempts',
                'invalid_value',
                'Action max_attempts must be a positive integer when provided.',
            );
        }

        if ($node->timeoutSeconds() !== null && $node->timeoutSeconds() < 1) {
            $this->addError(
                $nodePath.'.config.timeout_seconds',
                'invalid_value',
                'Action timeout_seconds must be a positive integer when provided.',
            );
        }

        if ($node->retry() !== null) {
            $retry = $node->retry();

            if ($retry === []) {
                $this->addError(
                    $nodePath.'.config.retry',
                    'invalid_value',
                    'Action retry configuration must be a non-empty object when provided.',
                );
            }

            if (isset($retry['max_attempts']) && (! is_int($retry['max_attempts']) || $retry['max_attempts'] < 1)) {
                $this->addError(
                    $nodePath.'.config.retry.max_attempts',
                    'invalid_value',
                    'Action retry.max_attempts must be a positive integer.',
                );
            }

            if (isset($retry['backoff_seconds']) && (! is_int($retry['backoff_seconds']) || $retry['backoff_seconds'] < 0)) {
                $this->addError(
                    $nodePath.'.config.retry.backoff_seconds',
                    'invalid_value',
                    'Action retry.backoff_seconds must be a non-negative integer.',
                );
            }
        }

        if (($node->maxAttempts() !== null || $node->retry() !== null || $node->timeoutSeconds() !== null || $node->allowManualSkip())
            && $mode->isLegacy()
        ) {
            $this->requireCapability(
                RuntimeCapability::ReliableAction,
                $nodePath.'.config',
                'Reliable Action configuration fields require the reliable_action runtime capability.',
            );
        }
    }

    private function validateSchemaVersion(string $schemaVersion): void
    {
        $this->validateSchemaVersionValue($schemaVersion);
    }

    private function validateSchemaVersionValue(mixed $schemaVersion): void
    {
        try {
            DefinitionSchemaVersion::fromMixed($schemaVersion);
        } catch (InvalidArgumentException $exception) {
            $this->addError(
                WorkflowDefinitionSchema::FIELD_SCHEMA_VERSION,
                'unsupported_schema_version',
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function validateRuntimeCapabilitiesFromDefinition(array $definition): void
    {
        if (array_key_exists(WorkflowDefinitionSchema::FIELD_CONTEXT_POLICY, $definition)) {
            $contextPolicy = $definition[WorkflowDefinitionSchema::FIELD_CONTEXT_POLICY];

            if (! is_array($contextPolicy)) {
                $this->addError(
                    WorkflowDefinitionSchema::FIELD_CONTEXT_POLICY,
                    'invalid_value',
                    'context_policy must be an object when provided.',
                );
            } else {
                $this->requireCapability(
                    RuntimeCapability::ContextSchemaV11,
                    WorkflowDefinitionSchema::FIELD_CONTEXT_POLICY,
                    'context_policy requires the context_schema_v1_1 runtime capability.',
                );

                $dataSource = $contextPolicy[WorkflowDefinitionSchema::CONTEXT_POLICY_DATA_SOURCE] ?? null;

                if ($dataSource === null || $dataSource === '') {
                    // Default snapshot semantics; no extra capability beyond context_schema_v1_1.
                } elseif (! is_string($dataSource)) {
                    $this->addError(
                        WorkflowDefinitionSchema::FIELD_CONTEXT_POLICY.'.'.WorkflowDefinitionSchema::CONTEXT_POLICY_DATA_SOURCE,
                        'invalid_value',
                        'context_policy.data_source must be snapshot or live.',
                    );
                } else {
                    $source = ContextDataSource::tryFrom($dataSource);

                    if ($source === null) {
                        $this->addError(
                            WorkflowDefinitionSchema::FIELD_CONTEXT_POLICY.'.'.WorkflowDefinitionSchema::CONTEXT_POLICY_DATA_SOURCE,
                            'invalid_value',
                            'context_policy.data_source must be snapshot or live.',
                        );
                    } elseif ($source === ContextDataSource::Live) {
                        $this->requireCapability(
                            RuntimeCapability::LiveContext,
                            WorkflowDefinitionSchema::FIELD_CONTEXT_POLICY.'.'.WorkflowDefinitionSchema::CONTEXT_POLICY_DATA_SOURCE,
                            'Live context requires the live_context runtime capability.',
                        );
                    }
                }
            }
        }

        foreach (['delegation', 'delegations'] as $forbiddenRoot) {
            if (array_key_exists($forbiddenRoot, $definition)) {
                $this->addError(
                    $forbiddenRoot,
                    'unsupported_definition_field',
                    "Definition field [{$forbiddenRoot}] is not supported; use approval node config.delegation.enabled and Core delegation rules.",
                );
            }
        }

        foreach (['sla', 'webhook', 'outbound_webhook'] as $forbiddenRoot) {
            if (array_key_exists($forbiddenRoot, $definition)) {
                $capability = $forbiddenRoot === 'sla'
                    ? RuntimeCapability::Sla
                    : RuntimeCapability::OutboundWebhook;

                $this->requireCapability(
                    $capability,
                    $forbiddenRoot,
                    "Definition field [{$forbiddenRoot}] requires runtime capability [{$capability->value}].",
                );
            }
        }

        $nodes = $definition[WorkflowDefinitionSchema::FIELD_NODES] ?? null;

        if (! is_array($nodes)) {
            return;
        }

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeType = $node[WorkflowDefinitionSchema::FIELD_TYPE] ?? null;
            $config = is_array($node[WorkflowDefinitionSchema::FIELD_CONFIG] ?? null)
                ? $node[WorkflowDefinitionSchema::FIELD_CONFIG]
                : [];

            if (array_key_exists(WorkflowDefinitionSchema::CONFIG_DELEGATION, $config)) {
                $delegationConfig = $config[WorkflowDefinitionSchema::CONFIG_DELEGATION];
                $enabled = $this->toggleEnabled($delegationConfig);

                if ($nodeType !== WorkflowDefinitionSchema::NODE_TYPE_APPROVAL) {
                    $this->addError(
                        'nodes.'.$index.'.config.delegation',
                        'invalid_node_type',
                        'delegation configuration is only valid on approval nodes.',
                    );
                } elseif ($enabled) {
                    $this->requireCapability(
                        RuntimeCapability::Delegation,
                        'nodes.'.$index.'.config.delegation',
                        'Node config [delegation] requires runtime capability [delegation].',
                    );
                }
            }

            if (array_key_exists(WorkflowDefinitionSchema::CONFIG_REASSIGNMENT, $config)) {
                $reassignmentConfig = $config[WorkflowDefinitionSchema::CONFIG_REASSIGNMENT];

                if ($nodeType !== WorkflowDefinitionSchema::NODE_TYPE_APPROVAL) {
                    $this->addError(
                        'nodes.'.$index.'.config.reassignment',
                        'invalid_node_type',
                        'reassignment configuration is only valid on approval nodes.',
                    );
                } elseif (! $this->isValidToggleConfig($reassignmentConfig)) {
                    $this->addError(
                        'nodes.'.$index.'.config.reassignment',
                        'invalid_value',
                        'reassignment must be a boolean or an object with an enabled boolean.',
                    );
                }
            }

            foreach (['sla', 'webhook', 'outbound_webhook'] as $forbiddenConfigKey) {
                if (array_key_exists($forbiddenConfigKey, $config)) {
                    $capability = match ($forbiddenConfigKey) {
                        'sla' => RuntimeCapability::Sla,
                        default => RuntimeCapability::OutboundWebhook,
                    };

                    $this->requireCapability(
                        $capability,
                        'nodes.'.$index.'.config.'.$forbiddenConfigKey,
                        "Node config [{$forbiddenConfigKey}] requires runtime capability [{$capability->value}].",
                    );
                }
            }
        }
    }

    private function toggleEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value) && array_key_exists(WorkflowDefinitionSchema::CONFIG_ENABLED, $value)) {
            return $value[WorkflowDefinitionSchema::CONFIG_ENABLED] === true;
        }

        return false;
    }

    private function isValidToggleConfig(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        if (! is_array($value) || ! array_key_exists(WorkflowDefinitionSchema::CONFIG_ENABLED, $value)) {
            return false;
        }

        return is_bool($value[WorkflowDefinitionSchema::CONFIG_ENABLED]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function validateRawActionNodeConfigs(array $definition): void
    {
        $nodes = $definition[WorkflowDefinitionSchema::FIELD_NODES] ?? null;

        if (! is_array($nodes)) {
            return;
        }

        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ($node[WorkflowDefinitionSchema::FIELD_TYPE] ?? null) !== WorkflowDefinitionSchema::NODE_TYPE_ACTION) {
                continue;
            }

            $nodePath = 'nodes.'.$index;
            $config = is_array($node[WorkflowDefinitionSchema::FIELD_CONFIG] ?? null)
                ? $node[WorkflowDefinitionSchema::FIELD_CONFIG]
                : [];

            if (array_key_exists(WorkflowDefinitionSchema::CONFIG_EXECUTION_MODE, $config)) {
                try {
                    ActionExecutionMode::normalize($config[WorkflowDefinitionSchema::CONFIG_EXECUTION_MODE]);
                } catch (InvalidArgumentException $exception) {
                    $this->addError(
                        $nodePath.'.config.execution_mode',
                        'invalid_value',
                        $exception->getMessage(),
                    );
                }
            }

            if (array_key_exists(WorkflowDefinitionSchema::CONFIG_MAX_ATTEMPTS, $config)) {
                $value = $config[WorkflowDefinitionSchema::CONFIG_MAX_ATTEMPTS];

                if (! $this->isPositiveIntLike($value)) {
                    $this->addError(
                        $nodePath.'.config.max_attempts',
                        'invalid_value',
                        'Action max_attempts must be a positive integer when provided.',
                    );
                }
            }

            if (array_key_exists(WorkflowDefinitionSchema::CONFIG_ACTION_TIMEOUT, $config)) {
                $value = $config[WorkflowDefinitionSchema::CONFIG_ACTION_TIMEOUT];

                if (! $this->isPositiveIntLike($value)) {
                    $this->addError(
                        $nodePath.'.config.timeout_seconds',
                        'invalid_value',
                        'Action timeout_seconds must be a positive integer when provided.',
                    );
                }
            }

            if (array_key_exists(WorkflowDefinitionSchema::CONFIG_RETRY, $config)) {
                $retry = $config[WorkflowDefinitionSchema::CONFIG_RETRY];

                if (! is_array($retry) || $retry === []) {
                    $this->addError(
                        $nodePath.'.config.retry',
                        'invalid_value',
                        'Action retry configuration must be a non-empty object when provided.',
                    );
                }
            }
        }
    }

    private function isPositiveIntLike(mixed $value): bool
    {
        if (is_int($value) && $value >= 1) {
            return true;
        }

        return is_string($value) && ctype_digit($value) && (int) $value >= 1;
    }

    private function addMappedHydrationError(string $message): void
    {
        if (str_contains($message, 'config.execution_mode')) {
            $this->addError('nodes', 'invalid_value', $message);

            return;
        }

        if (str_contains($message, 'config.max_attempts')) {
            $this->addError('nodes', 'invalid_value', $message);

            return;
        }

        if (str_contains($message, 'config.timeout_seconds')) {
            $this->addError('nodes', 'invalid_value', $message);

            return;
        }

        if (str_contains($message, 'config.retry')) {
            $this->addError('nodes', 'invalid_value', $message);

            return;
        }

        $this->addError('nodes', 'invalid_structure', $message);
    }

    private function requireCapability(RuntimeCapability $capability, string $path, string $message): void
    {
        if ($this->runtimeCapabilityRegistry !== null && $this->runtimeCapabilityRegistry->has($capability)) {
            return;
        }

        $this->addError($path, 'missing_runtime_capability', $message);
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
