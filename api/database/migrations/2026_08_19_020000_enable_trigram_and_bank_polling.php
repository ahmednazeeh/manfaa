<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP12 — automatic payment verification (owner spec 2026-08-19).
 *
 * After a customer uploads their receipt we watch the bank for the money for
 * a bounded window, and match an incoming credit to the order by amount and
 * by the payer's NAME. Names arrive as the bank recorded them — "AHMD N
 * ADAM" for "Ahmed Nazeeh Adam" — so the comparison has to tolerate dropped
 * vowels and initials, which is what pg_trgm is for.
 *
 * The window is BOUNDED (15 minutes by default) on purpose: a poller with no
 * end runs forever against somebody else's API for orders that were never
 * paid, and the admin queue is the honest home for anything it does not
 * find.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::table('orders', function (Blueprint $table): void {
            // When watching started, and when it may stop. Both stored so a
            // restarted worker knows how much of the window is left rather
            // than beginning again.
            $table->timestampTz('poll_started_at')->nullable();
            $table->timestampTz('poll_until')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);

            // What the match found, kept whether or not it verified: an
            // operator looking at a REFUSED auto-verification needs to see
            // what the bank actually said.
            $table->string('matched_trx_id')->nullable();
            $table->string('matched_payer_name')->nullable();
            $table->unsignedInteger('matched_score')->nullable();
            $table->boolean('auto_verified')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'poll_started_at',
                'poll_until',
                'poll_attempts',
                'matched_trx_id',
                'matched_payer_name',
                'matched_score',
                'auto_verified',
            ]);
        });
    }
};
