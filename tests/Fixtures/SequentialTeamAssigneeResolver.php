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

namespace DbflowLabs\Core\Tests\Fixtures;

use DbflowLabs\Core\Contracts\AssigneeResolver;
use DbflowLabs\Core\Models\WorkflowInstance;

final class SequentialTeamAssigneeResolver implements AssigneeResolver
{
    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        private readonly array $userIds,
    ) {}

    public function resolve(WorkflowInstance $instance, array $nodeDefinition): array
    {
        return $this->userIds;
    }
}
