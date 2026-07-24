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

namespace DbflowLabs\Core\Services\Actions;

use DbflowLabs\Core\Models\WorkflowInstance;
use Illuminate\Support\Facades\DB;

final class ActionVisitIdentityAllocator
{
    private const METADATA_COUNTERS_KEY = 'action_visit_counters';

    public function allocate(WorkflowInstance $instance, string $nodeKey): int
    {
        return DB::transaction(function () use ($instance, $nodeKey): int {
            /** @var WorkflowInstance $locked */
            $locked = WorkflowInstance::query()->whereKey($instance->getKey())->lockForUpdate()->firstOrFail();

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $counters = is_array($metadata[self::METADATA_COUNTERS_KEY] ?? null)
                ? $metadata[self::METADATA_COUNTERS_KEY]
                : [];

            $next = ((int) ($counters[$nodeKey] ?? 0)) + 1;
            $counters[$nodeKey] = $next;
            $metadata[self::METADATA_COUNTERS_KEY] = $counters;

            $locked->forceFill(['metadata' => $metadata])->save();

            return $next;
        });
    }

    public function buildLogicalExecutionKey(int $instanceId, string $nodeKey, int $visitSequence): string
    {
        return "instance:{$instanceId}:node:{$nodeKey}:visit:{$visitSequence}";
    }
}
