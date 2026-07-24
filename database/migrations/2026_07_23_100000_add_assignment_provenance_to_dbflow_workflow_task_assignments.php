<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('dbflow_workflow_task_assignments', 'assignment_source')) {
                $table->string('assignment_source', 32)->nullable()->after('status');
            }

            if (! Schema::hasColumn('dbflow_workflow_task_assignments', 'original_assignee_user_id')) {
                $table->string('original_assignee_user_id', 64)->nullable()->after('assignee_user_id');
            }

            if (! Schema::hasColumn('dbflow_workflow_task_assignments', 'effective_assignee_user_id')) {
                $table->string('effective_assignee_user_id', 64)->nullable()->after('original_assignee_user_id');
            }

            if (! Schema::hasColumn('dbflow_workflow_task_assignments', 'delegation_id')) {
                $table->unsignedBigInteger('delegation_id')->nullable()->after('assignment_source');
            }

            if (! Schema::hasColumn('dbflow_workflow_task_assignments', 'previous_assignment_id')) {
                $table->unsignedBigInteger('previous_assignment_id')->nullable()->after('delegation_id');
            }

            if (! Schema::hasColumn('dbflow_workflow_task_assignments', 'reassignment_operation_key')) {
                $table->string('reassignment_operation_key', 128)->nullable()->after('previous_assignment_id');
            }
        });

        Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
            $table->index(
                ['effective_assignee_user_id', 'status'],
                'idx_dbflow_wf_task_assignments_effective_status',
            );
            $table->index('delegation_id', 'idx_dbflow_wf_task_assignments_delegation');
            $table->unique(
                ['workflow_task_id', 'reassignment_operation_key'],
                'uq_dbflow_wf_task_assignments_task_reassign_key',
            );
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
            $table->dropUnique('uq_dbflow_wf_task_assignments_task_reassign_key');
            $table->dropIndex('idx_dbflow_wf_task_assignments_delegation');
            $table->dropIndex('idx_dbflow_wf_task_assignments_effective_status');

            foreach ([
                'reassignment_operation_key',
                'previous_assignment_id',
                'delegation_id',
                'assignment_source',
                'effective_assignee_user_id',
                'original_assignee_user_id',
            ] as $column) {
                if (Schema::hasColumn('dbflow_workflow_task_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
