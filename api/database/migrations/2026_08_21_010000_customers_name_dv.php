<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's name written in Thaana (owner, 2026-08-21).
 *
 * Nullable, and it stays nullable: the value is produced by a queued job that
 * asks Claude to transliterate the English name, and that job is allowed to
 * fail. A customer with no Dhivehi name is a customer the app shows in
 * English — never a customer who could not sign up.
 *
 * `name_dv` matches the column every other Thaana name in this schema already
 * uses (merchants, products, zones, roles…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('name_dv', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('name_dv');
        });
    }
};
