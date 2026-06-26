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

use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Exceptions\InvalidWorkflowDefinitionException;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Services\AssigneeResolverRegistry;

final class ApprovalNodeAssigneeResolver
{
    public function __construct(
        private readonly AssigneeResolverRegistry $assigneeResolverRegistry,
    ) {}

    /**
     * @return list<int>
     */
    public function resolveOrFail(WorkflowInstance $instance, ApprovalNode $node): array
    {
        $assigneeUserIds = $this->assigneeResolverRegistry->resolve($instance, $node);

        if ($assigneeUserIds === []) {
            throw new InvalidWorkflowDefinitionException(
                "No assignees resolved for approval node [{$node->key()}].",
            );
        }

        return $assigneeUserIds;
    }
}
