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
            $table->string('model_type', 255)
                ->nullable()
                ->after('description')
                ->comment('Fully qualified class name of the host business model for UI binding');

            $table->index('model_type', 'idx_dbflow_workflows_model_type');
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflows', function (Blueprint $table): void {
            $table->dropIndex('idx_dbflow_workflows_model_type');
            $table->dropColumn('model_type');
        });
    }
};
