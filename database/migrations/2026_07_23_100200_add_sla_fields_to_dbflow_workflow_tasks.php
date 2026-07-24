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
            if (! Schema::hasColumn('dbflow_workflow_tasks', 'overdue_at')) {
                $table->timestamp('overdue_at')->nullable()->after('due_at');
            }

            if (! Schema::hasColumn('dbflow_workflow_tasks', 'sla_policy_snapshot')) {
                $table->json('sla_policy_snapshot')->nullable()->after('overdue_at');
            }

            if (! Schema::hasColumn('dbflow_workflow_tasks', 'sla_policy_source')) {
                $table->string('sla_policy_source', 32)->nullable()->after('sla_policy_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflow_tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('dbflow_workflow_tasks', 'sla_policy_source')) {
                $table->dropColumn('sla_policy_source');
            }

            if (Schema::hasColumn('dbflow_workflow_tasks', 'sla_policy_snapshot')) {
                $table->dropColumn('sla_policy_snapshot');
            }

            if (Schema::hasColumn('dbflow_workflow_tasks', 'overdue_at')) {
                $table->dropColumn('overdue_at');
            }
        });
    }
};
