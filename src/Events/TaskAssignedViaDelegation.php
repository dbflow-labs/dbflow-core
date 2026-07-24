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

use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;

final class TaskAssignedViaDelegation
{
    public function __construct(
        public readonly WorkflowTask $task,
        public readonly WorkflowInstance $instance,
        public readonly WorkflowTaskAssignment $assignment,
        public readonly string $originalUserId,
        public readonly string $effectiveUserId,
        public readonly int $delegationId,
    ) {}
}
