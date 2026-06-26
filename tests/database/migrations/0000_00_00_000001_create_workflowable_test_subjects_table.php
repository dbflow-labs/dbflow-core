<?php

/**
 * This file is part of the dbflow-labs/core package.
 *
 * Copyright (c) 2026 Baron Wang <hello@dbflow.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 * @link    https://dbflow.dev
 * @see     https://github.com/dbflow-labs/dbflow-core
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uuid_test_subjects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('reference_code', 64);
            $table->timestamps();
        });

        Schema::create('integer_test_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_code', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integer_test_subjects');
        Schema::dropIfExists('uuid_test_subjects');
    }
};
