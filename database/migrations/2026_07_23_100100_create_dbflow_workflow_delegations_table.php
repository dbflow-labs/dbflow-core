<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dbflow_workflow_delegations', function (Blueprint $table): void {
            $table->id();
            $table->string('delegator_user_id', 64);
            $table->string('delegate_user_id', 64);
            $table->string('workflow_key', 64)->nullable();
            $table->string('node_key', 64)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('reason')->nullable();
            $table->string('created_by_user_id', 64)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_user_id', 64)->nullable();
            $table->text('revocation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['delegator_user_id', 'starts_at', 'ends_at', 'revoked_at'],
                'idx_dbflow_delegations_delegator_time',
            );
            $table->index(['delegate_user_id', 'revoked_at'], 'idx_dbflow_delegations_delegate');
            $table->index(
                ['workflow_key', 'node_key', 'starts_at', 'ends_at'],
                'idx_dbflow_delegations_scope_time',
            );
            $table->index('revoked_at', 'idx_dbflow_delegations_revoked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dbflow_workflow_delegations');
    }
};
