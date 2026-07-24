<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dbflow_workflow_action_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_action_execution_id');
            $table->unsignedInteger('attempt_number');
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();

            $table->foreign('workflow_action_execution_id', 'fk_dbflow_wf_action_attempts_execution')
                ->references('id')
                ->on('dbflow_workflow_action_executions')
                ->cascadeOnDelete();

            $table->unique(
                ['workflow_action_execution_id', 'attempt_number'],
                'uniq_dbflow_wf_action_attempts_execution_attempt',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dbflow_workflow_action_attempts');
    }
};
