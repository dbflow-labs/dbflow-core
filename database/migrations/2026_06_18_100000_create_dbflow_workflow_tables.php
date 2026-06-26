<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dbflow_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->comment('Unique workflow key');
            $table->string('name', 120)->comment('Workflow display name');
            $table->text('description')->nullable()->comment('Description');
            $table->boolean('is_enabled')->default(true)->comment('Whether the workflow is enabled');
            $table->timestamps();

            $table->unique('key', 'uq_dbflow_workflows_key');
            $table->index('is_enabled', 'idx_dbflow_workflows_enabled');
        });

        Schema::create('dbflow_workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('dbflow_workflows')->cascadeOnDelete();
            $table->unsignedInteger('version')->comment('Version number');
            $table->json('definition')->comment('Workflow definition JSON');
            $table->boolean('is_active')->default(false)->comment('Whether this version is the active published version');
            $table->timestamp('published_at')->nullable()->comment('Published at timestamp');
            $table->timestamps();

            $table->unique(['workflow_id', 'version'], 'uq_dbflow_workflow_versions_workflow_version');
            $table->index(['workflow_id', 'is_active'], 'idx_dbflow_workflow_versions_workflow_active');
        });

        Schema::create('dbflow_workflow_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('dbflow_workflows')->restrictOnDelete();
            $table->foreignId('workflow_version_id')->constrained('dbflow_workflow_versions')->restrictOnDelete();
            $table->string('workflowable_type', 128)->comment('Workflowable model type');
            $table->string('workflowable_id', 36)->comment('Workflowable model id');
            $table->string('business_key', 128)->nullable()->comment('Business key snapshot');
            $table->string('status', 32)->comment('Instance status');
            $table->string('current_node_key', 64)->nullable()->comment('Current node key');
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->comment('Started at timestamp');
            $table->timestamp('completed_at')->nullable()->comment('Completed at timestamp');
            $table->timestamp('cancelled_at')->nullable()->comment('Cancelled at timestamp');
            $table->json('metadata')->nullable()->comment('Extended metadata JSON');
            $table->timestamps();

            $table->index(['workflowable_type', 'workflowable_id'], 'idx_dbflow_wf_instances_workflowable');
            $table->index(['workflowable_type', 'workflowable_id', 'status'], 'idx_dbflow_wf_instances_workflowable_status');
            $table->index(['status', 'started_at'], 'idx_dbflow_wf_instances_status_started');
            $table->index('started_by_user_id', 'idx_dbflow_wf_instances_started_by');
        });

        Schema::create('dbflow_workflow_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('dbflow_workflow_instances')->cascadeOnDelete();
            $table->string('node_key', 64)->comment('Node key');
            $table->string('node_name', 120)->nullable()->comment('Node name snapshot');
            $table->string('status', 32)->comment('Task status');
            $table->string('approval_mode', 16)->nullable()->comment('Approval mode');
            $table->timestamp('due_at')->nullable()->comment('Due at timestamp');
            $table->timestamp('completed_at')->nullable()->comment('Completed at timestamp');
            $table->timestamps();

            $table->index(['workflow_instance_id', 'status'], 'idx_dbflow_wf_tasks_instance_status');
            $table->index(['status', 'created_at'], 'idx_dbflow_wf_tasks_status_created');
        });

        Schema::create('dbflow_workflow_task_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_task_id')->constrained('dbflow_workflow_tasks')->cascadeOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->comment('Assignment status');
            $table->unsignedInteger('sequence')->nullable()->comment('Serial approval sequence; lower numbers approve first');
            $table->timestamp('acted_at')->nullable()->comment('Acted at timestamp');
            $table->timestamps();

            $table->unique(['workflow_task_id', 'assignee_user_id'], 'uq_dbflow_wf_task_assignments_task_user');
            $table->index(['workflow_task_id', 'sequence'], 'idx_dbflow_wf_task_assignments_task_sequence');
            $table->index(['assignee_user_id', 'status'], 'idx_dbflow_wf_task_assignments_user_status');
        });

        Schema::create('dbflow_workflow_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_instance_id')->constrained('dbflow_workflow_instances')->cascadeOnDelete();
            $table->foreignId('workflow_task_id')->nullable()->constrained('dbflow_workflow_tasks')->nullOnDelete();
            $table->string('event', 64)->comment('Event type');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable()->comment('Comment or approval note');
            $table->json('payload')->nullable()->comment('Event payload JSON');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workflow_instance_id', 'created_at'], 'idx_dbflow_wf_logs_instance_created');
            $table->index('event', 'idx_dbflow_wf_logs_event');
            $table->index('actor_user_id', 'idx_dbflow_wf_logs_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dbflow_workflow_logs');
        Schema::dropIfExists('dbflow_workflow_task_assignments');
        Schema::dropIfExists('dbflow_workflow_tasks');
        Schema::dropIfExists('dbflow_workflow_instances');
        Schema::dropIfExists('dbflow_workflow_versions');
        Schema::dropIfExists('dbflow_workflows');
    }
};
