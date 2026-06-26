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

namespace DbflowLabs\Core\Contracts;

use DbflowLabs\Core\Models\WorkflowInstance;

interface WorkflowHooks
{
    public function onStarted(WorkflowInstance $instance): void;

    public function onApproved(WorkflowInstance $instance): void;

    public function onRejected(WorkflowInstance $instance): void;

    public function onCancelled(WorkflowInstance $instance): void;
}
