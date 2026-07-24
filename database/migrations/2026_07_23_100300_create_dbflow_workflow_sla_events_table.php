<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dbflow_workflow_sla_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_task_id');
            $table->unsignedBigInteger('workflow_instance_id');
            $table->string('node_key', 64);
            $table->string('event_type', 32);
            $table->unsignedInteger('sequence')->default(1);
            $table->timestamp('scheduled_at');
            $table->string('status', 32)->default('pending');
            $table->string('idempotency_key', 191);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('policy_snapshot');
            $table->json('result_metadata')->nullable();
            $table->timestamps();

            $table->foreign('workflow_task_id')
                ->references('id')
                ->on('dbflow_workflow_tasks')
                ->cascadeOnDelete();
            $table->foreign('workflow_instance_id')
                ->references('id')
                ->on('dbflow_workflow_instances')
                ->cascadeOnDelete();

            $table->unique('idempotency_key', 'uniq_dbflow_wf_sla_events_idempotency');
            $table->index(['status', 'scheduled_at', 'next_attempt_at'], 'idx_dbflow_wf_sla_events_dispatch');
            $table->index(['workflow_task_id', 'status'], 'idx_dbflow_wf_sla_events_task_status');
            $table->index(['workflow_instance_id', 'event_type'], 'idx_dbflow_wf_sla_events_instance_type');
            $table->index(['status', 'processing_started_at'], 'idx_dbflow_wf_sla_events_stale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dbflow_workflow_sla_events');
    }
};
