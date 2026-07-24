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

namespace DbflowLabs\Core\Services\Sla;

use DbflowLabs\Core\Definitions\Nodes\ApprovalNode;
use DbflowLabs\Core\Enums\SlaPolicySource;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Sla\SlaDuration;
use DbflowLabs\Core\Sla\SlaPolicy;
use Illuminate\Support\Carbon;

final class TaskSlaInitializer
{
    public function __construct(
        private readonly SlaEventMaterializer $materializer,
    ) {}

    public function initialize(
        WorkflowTask $task,
        WorkflowInstance $instance,
        ApprovalNode $node,
        ?Carbon $referenceTime = null,
    ): void {
        if (! $node->hasSla()) {
            return;
        }

        $slaConfig = $node->slaConfig();

        if ($slaConfig === null) {
            return;
        }

        $policy = SlaPolicy::fromConfigArray($slaConfig);
        $referenceTime = ($referenceTime ?? $task->created_at ?? now())->copy()->utc();
        $dueAt = SlaDuration::parse($policy->dueAfter)->addTo($referenceTime);

        $task->forceFill([
            'due_at' => $dueAt,
            'sla_policy_snapshot' => $policy->toSnapshotArray(),
            'sla_policy_source' => SlaPolicySource::V11Sla->value,
        ])->save();

        $this->materializer->materialize($task->refresh(), $instance, $policy, $referenceTime);
    }

    public static function usesSlaPath(WorkflowTask $task): bool
    {
        return $task->sla_policy_source === SlaPolicySource::V11Sla->value;
    }
}

