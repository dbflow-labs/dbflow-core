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

use DbflowLabs\Core\Exceptions\WorkflowNotAvailableException;

final class DbflowRuntime
{
    public static function isEnabled(): bool
    {
        return filter_var(config('dbflow.enabled', env('DBFLOW_ENABLED', true)), FILTER_VALIDATE_BOOL);
    }

    /**
     * @throws WorkflowNotAvailableException
     */
    public static function ensureEnabled(): void
    {
        if (! self::isEnabled()) {
            throw new WorkflowNotAvailableException(
                'DBFlow is disabled. Set DBFLOW_ENABLED=true or config(dbflow.enabled) to enable the workflow runtime.',
            );
        }
    }
}
