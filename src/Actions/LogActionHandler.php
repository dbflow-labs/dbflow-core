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

namespace DbflowLabs\Core\Actions;

use DbflowLabs\Core\Contracts\ActionHandler;
use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Services\WorkflowLogger;

final class LogActionHandler implements ActionHandler
{
    public function __construct(
        private readonly WorkflowLogger $logger,
    ) {}

    public function execute(ActionNode $node, WorkflowInstance $instance): void
    {
        $payload = $node->payload() ?? [];

        $message = isset($payload['message']) && is_string($payload['message'])
            ? $payload['message']
            : $node->name();

        $event = isset($payload['event']) && is_string($payload['event']) && $payload['event'] !== ''
            ? $payload['event']
            : 'action_log';

        $context = isset($payload['context']) && is_array($payload['context'])
            ? $payload['context']
            : [];

        $this->logger->log(
            $instance,
            $event,
            comment: $message,
            payload: array_merge(
                [
                    'node_key' => $node->key(),
                    'action_key' => $node->actionKey(),
                ],
                $context,
            ),
        );
    }
}
