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

namespace DbflowLabs\Core\Actions;

use DbflowLabs\Core\Enums\WorkflowInstanceStatus;
use DbflowLabs\Core\Enums\WorkflowLogEvent;
use DbflowLabs\Core\Enums\WorkflowTaskAssignmentStatus;
use DbflowLabs\Core\Enums\WorkflowTaskStatus;
use DbflowLabs\Core\Events\WorkflowCancelled;
use DbflowLabs\Core\Models\WorkflowInstance;
use DbflowLabs\Core\Models\WorkflowTask;
use DbflowLabs\Core\Models\WorkflowTaskAssignment;
use DbflowLabs\Core\Services\WorkflowHooksRegistry;
use DbflowLabs\Core\Services\WorkflowLogger;
use DbflowLabs\Core\Support\ResolvesActorUserId;
use DbflowLabs\Core\Support\ResolvesWorkflowHooks;
use Illuminate\Support\Facades\DB;

final class CancelWorkflow
{
    use ResolvesActorUserId;
    use ResolvesWorkflowHooks;

    public function __construct(
        private readonly WorkflowLogger $logger,
        private readonly ?WorkflowHooksRegistry $hooksRegistry = null,
    ) {}

    public function handle(WorkflowInstance $instance, mixed $actor = null, ?string $comment = null): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $actor, $comment): WorkflowInstance {
            /** @var WorkflowInstance $lockedInstance */
            $lockedInstance = WorkflowInstance::query()
                ->whereKey($instance->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInstance->status?->isTerminal() ?? false) {
                return $lockedInstance->refresh();
            }

            $pendingTaskIds = WorkflowTask::query()
                ->where('workflow_instance_id', $lockedInstance->getKey())
                ->where('status', WorkflowTaskStatus::Pending)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($pendingTaskIds !== []) {
                WorkflowTask::query()
                    ->whereIn('id', $pendingTaskIds)
                    ->update([
                        'status' => WorkflowTaskStatus::Cancelled,
                        'completed_at' => now(),
                    ]);

                WorkflowTaskAssignment::query()
                    ->whereIn('workflow_task_id', $pendingTaskIds)
                    ->where('status', WorkflowTaskAssignmentStatus::Pending)
                    ->update([
                        'status' => WorkflowTaskAssignmentStatus::Cancelled,
                        'acted_at' => now(),
                    ]);
            }

            $lockedInstance->forceFill([
                'status' => WorkflowInstanceStatus::Cancelled,
                'cancelled_at' => now(),
                'completed_at' => $lockedInstance->completed_at ?? now(),
                'active_key' => null,
            ])->save();

            $this->logger->log(
                $lockedInstance,
                WorkflowLogEvent::WorkflowCancelled,
                actor: $actor,
                comment: $comment,
            );

            $this->hooksForInstance($this->hooksRegistry, $lockedInstance->refresh())->onCancelled($lockedInstance->refresh());

            event(new WorkflowCancelled($lockedInstance->refresh()));

            return $lockedInstance->refresh();
        });
    }
}
