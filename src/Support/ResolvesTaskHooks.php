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

use DbflowLabs\Core\Contracts\TaskHooks;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Services\NullTaskHooks;
use DbflowLabs\Core\Services\TaskHooksRegistry;

trait ResolvesTaskHooks
{
    private function taskHooksForKey(?TaskHooksRegistry $hooksRegistry, string $workflowKey): TaskHooks
    {
        if ($hooksRegistry === null) {
            return new NullTaskHooks;
        }

        return $hooksRegistry->resolve($workflowKey);
    }

    private function taskHooksForInstance(?TaskHooksRegistry $hooksRegistry, WorkflowInstance $instance): TaskHooks
    {
        $instance->loadMissing('workflow');
        $key = $instance->workflow?->key;
        $workflowKey = is_string($key) && $key !== '' ? $key : '';

        return $this->taskHooksForKey($hooksRegistry, $workflowKey);
    }
}
