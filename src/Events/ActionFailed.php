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

namespace DbflowLabs\Core\Events;

use DbflowLabs\Core\Definitions\Nodes\ActionNode;
use DbflowLabs\Core\Models\WorkflowInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Dispatched whenever an action node handler throws. Unless the node is configured with
 * `stop_on_error`, execution continues after this event fires so hosts can react (alert,
 * retry, compensate) without Core forcing the workflow into a failed state.
 */
final class ActionFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly ActionNode $node,
        public readonly Throwable $exception,
    ) {}
}
