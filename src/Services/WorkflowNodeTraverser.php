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

namespace DbflowLabs\Core\Services;

use DbflowLabs\Core\Definitions\Blueprint;
use DbflowLabs\Core\Definitions\Contracts\WorkflowNodeInterface;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Events\ActionFailed as ActionFailedEvent;
use DbflowLabs\Core\Exceptions\ActionExecutionFailedException;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Exceptions\PremiumFeatureMissingException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Support\ActionManager;

final class WorkflowNodeTraverser
{
    public const MAX_ACTION_DEPTH = 20;

    public function __construct(
        private readonly TransitionResolver $transitionResolver,
        private readonly ActionManager $actionManager,
        private readonly WorkflowLogger $logger,
    ) {}

    /**
     * Walk the workflow graph from the given node through action chains until an approval or end node.
     *
     * @param  array<string, mixed>  $variables
     * @param  callable(WorkflowInstance, ApprovalNode, mixed): void  $onApprovalNode
     * @param  callable(WorkflowInstance, EndNode, mixed): void  $onEndNode
     * @return 'pending'|'completed'
     */
    public function traverse(
        WorkflowInstance $instance,
        Blueprint $blueprint,
        WorkflowNodeInterface $node,
        array $variables,
        mixed $actor,
        callable $onApprovalNode,
        callable $onEndNode,
        int $depth = 0,
    ): string {
        if ($depth > self::MAX_ACTION_DEPTH) {
            throw new InvalidWorkflowDefinitionException(
                'Workflow definition has consecutive action nodes exceeding the maximum depth of '.self::MAX_ACTION_DEPTH.'.',
            );
        }

        if ($node instanceof EndNode) {
            $onEndNode($instance, $node, $actor);

            return 'completed';
        }

        if ($node instanceof ActionNode) {
            $this->executeActionNode($instance, $node, $actor);

            $nextNode = $this->transitionResolver->nextNode(
                $blueprint,
                $node->key(),
                'approve',
                $variables,
            );

            if ($nextNode === null) {
                throw new InvalidWorkflowDefinitionException(
                    "Action node [{$node->key()}] has no outgoing transition in workflow definition.",
                );
            }

            return $this->traverse(
                $instance,
                $blueprint,
                $nextNode,
                $variables,
                $actor,
                $onApprovalNode,
                $onEndNode,
                $depth + 1,
            );
        }

        if ($node instanceof ApprovalNode) {
            $onApprovalNode($instance, $node, $actor);

            return 'pending';
        }

        throw new InvalidWorkflowDefinitionException(
            "Unsupported node type [{$node->type()->value}] in workflow definition.",
        );
    }

    private function executeActionNode(WorkflowInstance $instance, ActionNode $node, mixed $actor): void
    {
        $nodeKey = $node->key();
        $actionKey = $node->actionKey();

        $instance->forceFill(['current_node_key' => $nodeKey])->save();

        if ($actionKey === '') {
            $this->logger->log(
                $instance,
                WorkflowLogEvent::ActionExecuted,
                actor: $actor,
                payload: ['node_key' => $nodeKey, 'skipped' => true, 'reason' => 'no_action_key'],
            );

            return;
        }

        if (! $this->actionManager->has($actionKey)) {
            throw new PremiumFeatureMissingException($actionKey, $nodeKey);
        }

        $handler = $this->actionManager->resolve($actionKey);

        if ($handler === null) {
            throw new PremiumFeatureMissingException($actionKey, $nodeKey);
        }

        try {
            $handler->execute($node, $instance);

            $this->logger->log(
                $instance,
                WorkflowLogEvent::ActionExecuted,
                actor: $actor,
                payload: ['node_key' => $nodeKey, 'action_key' => $actionKey],
            );
        } catch (\Throwable $e) {
            $this->logger->log(
                $instance,
                WorkflowLogEvent::ActionFailed,
                actor: $actor,
                payload: [
                    'node_key' => $nodeKey,
                    'action_key' => $actionKey,
                    'error' => $e->getMessage(),
                    'stop_on_error' => $node->stopOnError(),
                ],
            );

            event(new ActionFailedEvent($instance, $node, $e));

            if ($node->stopOnError()) {
                throw new ActionExecutionFailedException($actionKey, $nodeKey, $e);
            }
        }
    }
}
