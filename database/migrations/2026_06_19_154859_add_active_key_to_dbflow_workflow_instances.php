<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dbflow_workflow_instances', function (Blueprint $table): void {
            // active_key holds a unique slot while the instance is Running; set to null on terminal state to release.
            // Format: {workflowKey}:{morphClass}:{workflowableId}
            // Database unique constraint is the final defense against double submission, supplementing assertNoRunningWorkflow.
            $table->string('active_key', 255)->nullable()->after('business_key');
            $table->unique('active_key', 'uq_dbflow_workflow_instances_active_key');
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflow_instances', function (Blueprint $table): void {
            $table->dropUnique('uq_dbflow_workflow_instances_active_key');
            $table->dropColumn('active_key');
        });
    }
};
