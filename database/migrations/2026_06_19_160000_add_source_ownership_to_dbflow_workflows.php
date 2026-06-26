<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dbflow_workflows', function (Blueprint $table): void {
            // source marks definition ownership: 'code' = managed by code sync, 'ui' = managed by UI configuration.
            // SyncWorkflowDefinitions skips primary version pointer updates for ui-owned workflows.
            $table->string('source', 20)->default('code')->after('key');

            // owner stores an owner identifier (e.g. user ID, email, or team name) for audit tracing.
            $table->string('owner')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflows', function (Blueprint $table): void {
            $table->dropColumn(['source', 'owner']);
        });
    }
};
