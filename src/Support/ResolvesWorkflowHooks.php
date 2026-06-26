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

use DbflowLabs\Core\Contracts\WorkflowHooks;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Services\NullWorkflowHooks;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;

trait ResolvesWorkflowHooks
{
    private function hooksForKey(?WorkflowHooksRegistry $hooksRegistry, string $workflowKey): WorkflowHooks
    {
        if ($hooksRegistry === null || $workflowKey === '') {
            return new NullWorkflowHooks;
        }

        return $hooksRegistry->resolve($workflowKey);
    }

    private function hooksForInstance(?WorkflowHooksRegistry $hooksRegistry, WorkflowInstance $instance): WorkflowHooks
    {
        return $this->hooksForKey($hooksRegistry, $this->resolveWorkflowKey($instance));
    }

    private function resolveWorkflowKey(WorkflowInstance $instance): string
    {
        $instance->loadMissing('workflow');

        $key = $instance->workflow?->key;

        return is_string($key) && $key !== '' ? $key : '';
    }
}
