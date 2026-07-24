<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dbflow_workflow_action_executions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_instance_id');
            $table->unsignedBigInteger('workflow_task_id')->nullable();
            $table->string('node_key', 64);
            $table->string('action_key', 128);
            $table->string('execution_mode', 32);
            $table->string('status', 32)->default('queued');
            $table->string('logical_execution_key', 191);
            $table->unsignedInteger('visit_sequence')->default(1);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('exhausted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamp('workflow_advanced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('node_snapshot');
            $table->json('payload_snapshot')->nullable();
            $table->json('result_metadata')->nullable();
            $table->unsignedInteger('response_status')->nullable();
            $table->string('actor_user_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('workflow_instance_id')
                ->references('id')
                ->on('dbflow_workflow_instances')
                ->cascadeOnDelete();
            $table->foreign('workflow_task_id')
                ->references('id')
                ->on('dbflow_workflow_tasks')
                ->nullOnDelete();

            $table->unique('logical_execution_key', 'uniq_dbflow_wf_action_exec_logical_key');
            $table->index(['status', 'next_attempt_at', 'queued_at'], 'idx_dbflow_wf_action_exec_dispatch');
            $table->index(['workflow_instance_id', 'node_key', 'status'], 'idx_dbflow_wf_action_exec_instance_node');
            $table->index(['status', 'processing_started_at'], 'idx_dbflow_wf_action_exec_stale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dbflow_workflow_action_executions');
    }
};
