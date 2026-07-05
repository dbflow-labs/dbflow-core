<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const USER_ID_LENGTH = 64;

    public function up(): void
    {
        $this->convertUserIdColumn(
            'dbflow_workflow_instances',
            'started_by_user_id',
            ['idx_dbflow_wf_instances_started_by'],
            static function (Blueprint $table): void {
                $table->index('started_by_user_id', 'idx_dbflow_wf_instances_started_by');
            },
        );

        $this->convertUserIdColumn(
            'dbflow_workflow_task_assignments',
            'assignee_user_id',
            [
                'uq_dbflow_wf_task_assignments_task_user',
                'idx_dbflow_wf_task_assignments_user_status',
            ],
            static function (Blueprint $table): void {
                $table->unique(['workflow_task_id', 'assignee_user_id'], 'uq_dbflow_wf_task_assignments_task_user');
                $table->index(['assignee_user_id', 'status'], 'idx_dbflow_wf_task_assignments_user_status');
            },
        );

        $this->convertUserIdColumn(
            'dbflow_workflow_logs',
            'actor_user_id',
            ['idx_dbflow_wf_logs_actor'],
            static function (Blueprint $table): void {
                $table->index('actor_user_id', 'idx_dbflow_wf_logs_actor');
            },
        );

        if (Schema::hasColumn('dbflow_workflows', 'draft_updated_by')) {
            $this->convertUserIdColumn('dbflow_workflows', 'draft_updated_by', []);
        }

        if (Schema::hasColumn('dbflow_workflow_versions', 'published_by')) {
            $this->convertUserIdColumn('dbflow_workflow_versions', 'published_by', []);
        }
    }

    public function down(): void
    {
        // Alpha rollback is not supported once UUID user ids are stored.
    }

    /**
     * @param  list<string>  $indexNames
     */
    private function convertUserIdColumn(
        string $table,
        string $column,
        array $indexNames,
        ?callable $reindex = null,
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $this->dropUserForeignKeys($table, $column);

        foreach ($indexNames as $indexName) {
            $this->dropIndexIfExists($table, $indexName);
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` VARCHAR(%d) NULL',
                $table,
                $column,
                self::USER_ID_LENGTH,
            ));
        } elseif ($driver === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE "%s" ALTER COLUMN "%s" TYPE VARCHAR(%d) USING "%s"::text',
                $table,
                $column,
                self::USER_ID_LENGTH,
                $column,
            ));
        } else {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->string($column, self::USER_ID_LENGTH)->nullable()->change();
            });
        }

        if ($reindex !== null) {
            Schema::table($table, $reindex);
        }
    }

    private function dropUserForeignKeys(string $table, string $column): void
    {
        $candidates = [
            "{$table}_{$column}_foreign",
            'fk_dbflow_wf_task_assignments_assignee_user',
            'dbflow_workflow_task_assignments_assignee_user_id_foreign',
        ];

        foreach ($candidates as $foreignKey) {
            try {
                if (Schema::getConnection()->getDriverName() === 'mysql') {
                    DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$foreignKey}`");
                } else {
                    Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                        $blueprint->dropForeign([$column]);
                    });
                }
            } catch (\Throwable) {
                // Foreign key may not exist on this installation.
            }
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
            // Ignore when the column has no foreign key.
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
                $blueprint->dropIndex($indexName);
            });
        } catch (\Throwable) {
            // Index may not exist on this installation.
        }
    }
};
