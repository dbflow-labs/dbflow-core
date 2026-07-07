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

interface AssigneeResolver
{
    /**
     * @param  array<string, mixed>  $nodeDefinition
     * @return list<int|string>  Resolved user ids; may be integers or strings (UUID/ULID) since
     *                            assignee_user_id is stored as VARCHAR(64).
     */
    public function resolve(WorkflowInstance $instance, array $nodeDefinition): array;
}
