<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dbflow_workflow_tasks', function (Blueprint $table): void {
            $table->unsignedInteger('iteration')
                ->default(1)
                ->after('workflow_instance_id')
                ->comment('Approval iteration; incremented on each rollback to distinguish repeated approvals on the same node');

            // The previous idx_dbflow_wf_tasks_instance_status index did not include iteration,
            // so add a composite index with iteration for precise instance/node/iteration lookups.
            $table->index(
                ['workflow_instance_id', 'node_key', 'iteration'],
                'idx_dbflow_wf_tasks_instance_node_iteration',
            );
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflow_tasks', function (Blueprint $table): void {
            $table->dropIndex('idx_dbflow_wf_tasks_instance_node_iteration');
            $table->dropColumn('iteration');
        });
    }
};
