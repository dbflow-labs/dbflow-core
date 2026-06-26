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

use DbflowLabs\Core\Definitions\Nodes\EndNode;
use DbflowLabs\Core\Definitions\WorkflowDefinitionSchema;
use DbflowLabs\Core\Enums\WorkflowInstanceStatus;

final class WorkflowCompletionStatus
{
    public static function fromEndNode(?EndNode $endNode): WorkflowInstanceStatus
    {
        if ($endNode === null) {
            return WorkflowInstanceStatus::Approved;
        }

        $status = $endNode->status();

        if ($status === null || $status === '') {
            return WorkflowInstanceStatus::Approved;
        }

        return match ($status) {
            WorkflowDefinitionSchema::END_NODE_STATUS_REJECTED => WorkflowInstanceStatus::Rejected,
            WorkflowDefinitionSchema::END_NODE_STATUS_CANCELLED => WorkflowInstanceStatus::Cancelled,
            default => WorkflowInstanceStatus::Approved,
        };
    }
}
