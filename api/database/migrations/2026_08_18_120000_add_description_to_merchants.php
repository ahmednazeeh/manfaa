<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store's own words (owner decision 2026-08-18): a short description
 * shoppers read on the store page, collected at signup and changed only
 * through admin review like every other public claim.
 *
 * Nullable in the column because the two stores already live predate it —
 * the REQUIREMENT is enforced at submit (OnboardingService), which is where
 * every other "a live store must have this" rule lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name_dv');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
