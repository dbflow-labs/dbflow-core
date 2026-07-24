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

namespace DbflowLabs\Core\Contracts\Actions;

use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Enums\ActionExecutionMode;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;

final class ReliableActionContext
{
    /**
     * @param  array<string, mixed>  $payloadSnapshot
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public readonly WorkflowActionExecution $execution,
        public readonly WorkflowInstance $instance,
        public readonly ?WorkflowTask $task,
        public readonly ActionNode $node,
        public readonly string $actionKey,
        public readonly ActionExecutionMode $executionMode,
        public readonly string $logicalExecutionKey,
        public readonly int $attemptNumber,
        public readonly array $payloadSnapshot,
        public readonly array $variables,
    ) {}
}
