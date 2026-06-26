<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dbflow_workflow_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('published_by')->nullable()->comment('Published by user');
        });

        Schema::table('dbflow_workflows', function (Blueprint $table): void {
            $table->foreignId('current_version_id')
                ->nullable()
                ->comment('Currently published version')
                ->constrained('dbflow_workflow_versions')
                ->nullOnDelete();
            $table->string('status', 32)->nullable()->comment('Workflow status');
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
            $table->dropColumn('status');
        });

        Schema::table('dbflow_workflow_versions', function (Blueprint $table): void {
            $table->dropColumn('published_by');
        });
    }
};
