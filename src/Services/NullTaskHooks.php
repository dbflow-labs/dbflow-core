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

use DbflowLabs\Core\Contracts\TaskHooks;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;

final class NullTaskHooks implements TaskHooks
{
    public function onTaskCreated(WorkflowTask $task, WorkflowInstance $instance): void {}

    public function onAfterApprove(WorkflowTask $task, WorkflowInstance $instance, mixed $actor): void {}

    public function onAfterReject(WorkflowTask $task, WorkflowInstance $instance, mixed $actor): void {}

    public function onReassigned(
        WorkflowTask $task,
        WorkflowInstance $instance,
        mixed $actor,
        string $toUserId,
    ): void {}
}
