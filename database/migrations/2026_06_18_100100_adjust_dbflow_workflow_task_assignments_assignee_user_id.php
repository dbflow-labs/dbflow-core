<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite test databases create nullable columns directly in the create migration; no MySQL MODIFY needed.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('dbflow_workflow_task_assignments')
            || ! Schema::hasColumn('dbflow_workflow_task_assignments', 'assignee_user_id')) {
            return;
        }

        Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
            $table->dropForeign(['assignee_user_id']);
        });

        DB::statement('ALTER TABLE `dbflow_workflow_task_assignments` MODIFY `assignee_user_id` BIGINT UNSIGNED NULL');

        Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
            $table->foreign('assignee_user_id', 'fk_dbflow_wf_task_assignments_assignee_user')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasTable('dbflow_workflow_task_assignments')
            || ! Schema::hasColumn('dbflow_workflow_task_assignments', 'assignee_user_id')) {
            return;
        }

        Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
            $table->dropForeign('fk_dbflow_wf_task_assignments_assignee_user');
        });

        DB::statement('ALTER TABLE `dbflow_workflow_task_assignments` MODIFY `assignee_user_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
            $table->foreign('assignee_user_id', 'dbflow_workflow_task_assignments_assignee_user_id_foreign')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
