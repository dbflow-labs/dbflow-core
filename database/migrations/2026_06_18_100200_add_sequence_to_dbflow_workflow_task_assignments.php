<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dbflow_workflow_task_assignments')) {
            return;
        }

        if (! Schema::hasColumn('dbflow_workflow_task_assignments', 'sequence')) {
            Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
                $table->unsignedInteger('sequence')
                    ->nullable()
                    ->after('status')
                    ->comment('Serial approval sequence; lower numbers approve first');
            });
        }

        if (! $this->indexExists('dbflow_workflow_task_assignments', 'idx_dbflow_wf_task_assignments_task_sequence')) {
            Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
                $table->index(['workflow_task_id', 'sequence'], 'idx_dbflow_wf_task_assignments_task_sequence');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dbflow_workflow_task_assignments')) {
            return;
        }

        if ($this->indexExists('dbflow_workflow_task_assignments', 'idx_dbflow_wf_task_assignments_task_sequence')) {
            Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
                $table->dropIndex('idx_dbflow_wf_task_assignments_task_sequence');
            });
        }

        if (Schema::hasColumn('dbflow_workflow_task_assignments', 'sequence')) {
            Schema::table('dbflow_workflow_task_assignments', function (Blueprint $table): void {
                $table->dropColumn('sequence');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }
};
