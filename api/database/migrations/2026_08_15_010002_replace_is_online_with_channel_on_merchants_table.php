<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §1 decision 2026-08-15: the is_online bool becomes a channel enum —
     * in_store / online / both (varchar + CHECK, like every enum here).
     * Display copy never says "both"; the frontends render "In Store &
     * Online" (localised). Backfill: true → online, false → in_store; no
     * existing row can be 'both'.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('channel', 16)->default('in_store');
        });

        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchants_channel_check CHECK (channel IN ('in_store', 'online', 'both'))");

        DB::statement("UPDATE merchants SET channel = CASE WHEN is_online THEN 'online' ELSE 'in_store' END");

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('is_online');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('is_online')->default(false);
        });

        // 'both' merchants do sell online — the bool cannot say more.
        DB::statement("UPDATE merchants SET is_online = channel IN ('online', 'both')");

        DB::statement('ALTER TABLE merchants DROP CONSTRAINT merchants_channel_check');

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
