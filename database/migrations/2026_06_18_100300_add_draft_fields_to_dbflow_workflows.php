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
            $table->json('draft_definition')->nullable()->comment('Editable draft definition JSON');
            $table->json('draft_validation_errors')->nullable()->comment('Draft validation errors');
            $table->timestamp('draft_updated_at')->nullable()->comment('Draft last updated timestamp');
            $table->unsignedBigInteger('draft_updated_by')->nullable()->comment('Draft last updated by user id');
        });
    }

    public function down(): void
    {
        Schema::table('dbflow_workflows', function (Blueprint $table): void {
            $table->dropColumn([
                'draft_definition',
                'draft_validation_errors',
                'draft_updated_at',
                'draft_updated_by',
            ]);
        });
    }
};
