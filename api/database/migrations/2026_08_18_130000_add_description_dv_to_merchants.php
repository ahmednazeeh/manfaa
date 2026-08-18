<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Dhivehi twin of the store description (owner decision 2026-08-18):
 * English is required, Dhivehi optional — the same bargain `name` and
 * `name_dv` already strike, so a Dhivehi reader gets Dhivehi when the shop
 * wrote it and English when it did not, with no new fallback logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->text('description_dv')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropColumn('description_dv');
        });
    }
};
