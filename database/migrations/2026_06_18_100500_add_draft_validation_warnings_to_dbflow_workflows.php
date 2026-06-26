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
            $table->json('draft_validation_warnings')->nullable()->comment('Draft validation warnings');
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflows', function (Blueprint $table): void {
            $table->dropColumn('draft_validation_warnings');
        });
    }
};
