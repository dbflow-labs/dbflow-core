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

namespace DbflowLabs\Core\Services\Diagnostics;

use DbflowLabs\Core\Capabilities\RuntimeCapabilityRegistry;
use DbflowLabs\Core\Contracts\Actions\WorkflowSecretResolver;
use DbflowLabs\Core\Enums\ActionExecutionStatus;
use DbflowLabs\Core\Enums\SlaEventStatus;
use DbflowLabs\Core\Models\WorkflowActionExecution;
use DbflowLabs\Core\Models\WorkflowSlaEvent;
use DbflowLabs\Core\Services\Actions\ReliableActionHandlerRegistry;
use DbflowLabs\Core\Services\Sla\SlaNotificationHandlerRegistry;
use DbflowLabs\Core\Support\DbflowRuntime;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class RuntimeDiagnostics
{
    public function __construct(
        private readonly RuntimeCapabilityRegistry $capabilityRegistry,
        private readonly ReliableActionHandlerRegistry $actionHandlerRegistry,
        private readonly SlaNotificationHandlerRegistry $slaNotificationHandlerRegistry,
        private readonly ?QueueFactory $queueFactory = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return [
            'dbflow_enabled' => DbflowRuntime::isEnabled(),
            'capabilities' => $this->capabilityRegistry->enabledCapabilities(),
            'sla' => [
                'capability_enabled' => $this->capabilityRegistry->has(\DbflowLabs\Core\Enums\RuntimeCapability::Sla),
                'pending_events' => WorkflowSlaEvent::query()
                    ->where('status', SlaEventStatus::Pending)
                    ->count(),
                'processing_events' => WorkflowSlaEvent::query()
                    ->where('status', SlaEventStatus::Processing)
                    ->count(),
                'failed_events' => WorkflowSlaEvent::query()
                    ->where('status', SlaEventStatus::Failed)
                    ->count(),
                'pending_overdue_events' => WorkflowSlaEvent::query()
                    ->where('status', SlaEventStatus::Pending)
                    ->where('event_type', 'overdue')
                    ->where('scheduled_at', '<=', Carbon::now('UTC'))
                    ->count(),
                'stale_processing_events' => $this->countStaleSlaEvents(),
                'notification_channels' => $this->slaNotificationHandlerRegistry->registeredChannels(),
                'migrations' => [
                    'sla_events_table' => Schema::hasTable('dbflow_workflow_sla_events'),
                    'task_sla_columns' => Schema::hasColumn('dbflow_workflow_tasks', 'sla_policy_source'),
                ],
            ],
            'actions' => [
                'capability_enabled' => $this->capabilityRegistry->has(\DbflowLabs\Core\Enums\RuntimeCapability::ReliableAction),
                'queued_executions' => WorkflowActionExecution::query()
                    ->where('status', ActionExecutionStatus::Queued)
                    ->count(),
                'running_executions' => WorkflowActionExecution::query()
                    ->where('status', ActionExecutionStatus::Running)
                    ->count(),
                'exhausted_executions' => WorkflowActionExecution::query()
                    ->where('status', ActionExecutionStatus::Exhausted)
                    ->count(),
                'stale_running_executions' => $this->countStaleActionExecutions(),
                'registered_handlers' => $this->actionHandlerRegistry->registeredActionKeys(),
                'migrations' => [
                    'executions_table' => Schema::hasTable('dbflow_workflow_action_executions'),
                    'attempts_table' => Schema::hasTable('dbflow_workflow_action_attempts'),
                ],
            ],
            'webhook' => [
                'capability_enabled' => $this->capabilityRegistry->has(\DbflowLabs\Core\Enums\RuntimeCapability::OutboundWebhook),
                'handler_registered' => $this->actionHandlerRegistry->has('outbound_webhook'),
                'secret_resolver_bound' => app()->bound(WorkflowSecretResolver::class),
                'require_https' => (bool) config('dbflow.webhook.require_https', false),
                'deny_private_ips' => (bool) config('dbflow.webhook.deny_private_ips', true),
                'follow_redirects' => (bool) config('dbflow.webhook.follow_redirects', false),
                'max_redirects' => (int) config('dbflow.webhook.max_redirects', 3),
                'host_allowlist_count' => count(config('dbflow.webhook.host_allowlist', [])),
                'signing_supported' => true,
                'idempotency_header' => (string) config('dbflow.webhook.idempotency_header', 'X-DBFlow-Idempotency-Key'),
                'max_request_body_length' => (int) config('dbflow.webhook.max_request_body_length', 65536),
                'max_response_body_length' => (int) config('dbflow.webhook.max_response_body_length', 4096),
            ],
            'queue' => $this->queueDiagnostics(),
            'scheduler' => [
                'recommended_commands' => [
                    'dbflow:sla-dispatch',
                    'dbflow:sla-recover',
                    'dbflow:actions-dispatch',
                    'dbflow:actions-recover',
                    'dbflow:process-timeouts',
                ],
                'note' => 'Core cannot verify that the host scheduler or queue workers are running.',
            ],
        ];
    }

    private function countStaleSlaEvents(): int
    {
        if (! Schema::hasTable('dbflow_workflow_sla_events')) {
            return 0;
        }

        $thresholdSeconds = (int) config('dbflow.sla.stale_processing_threshold_seconds', 900);
        $staleBefore = Carbon::now('UTC')->subSeconds($thresholdSeconds);

        return WorkflowSlaEvent::query()
            ->where('status', SlaEventStatus::Processing)
            ->whereNull('processed_at')
            ->whereNull('cancelled_at')
            ->where('processing_started_at', '<=', $staleBefore)
            ->count();
    }

    private function countStaleActionExecutions(): int
    {
        if (! Schema::hasTable('dbflow_workflow_action_executions')) {
            return 0;
        }

        $thresholdSeconds = (int) config('dbflow.actions.stale_processing_threshold_seconds', 900);
        $staleBefore = Carbon::now('UTC')->subSeconds($thresholdSeconds);

        return WorkflowActionExecution::query()
            ->where('status', ActionExecutionStatus::Running)
            ->whereNull('succeeded_at')
            ->whereNull('cancelled_at')
            ->where('processing_started_at', '<=', $staleBefore)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function queueDiagnostics(): array
    {
        $defaultConnection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$defaultConnection}.driver", $defaultConnection);

        return [
            'default_connection' => $defaultConnection,
            'driver' => $driver,
            'ready_for_async' => $driver !== 'sync',
            'factory_bound' => $this->queueFactory !== null,
        ];
    }
}
