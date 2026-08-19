<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-matching the OTHER direction: merchants paying the platform
 * (owner question 2026-08-19 — does /settlements match automatically too?).
 *
 * It should, and this side is easier than the customer one: the merchant
 * types their own transfer reference into `bank_ref` when they upload the
 * slip. A reference that appears in our bank history verbatim is proof of a
 * kind a name match can never be, so it is tried FIRST and outranks
 * everything else.
 *
 * `matched_by` stays null on an automatic match, exactly as `verified_by`
 * does on an order — no admin decided it, and filing one would put a
 * person's name on a machine's call. `auto_matched` is what records who did.
 *
 * The unique index is the same guarantee the orders table got: one bank
 * credit settles one thing, and a query check two workers could both win is
 * not a guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table): void {
            $table->boolean('auto_matched')->default(false);
            $table->string('matched_trx_id')->nullable();
            $table->string('matched_payer_name')->nullable();
            $table->unsignedInteger('matched_score')->nullable();
            // How the row was found: 'reference' (the merchant's own bank_ref
            // seen in our history) or 'name'. Kept because the two carry very
            // different weight, and an operator reviewing later needs to know
            // which one this was.
            $table->string('matched_by_rule')->nullable();

            $table->timestampTz('poll_started_at')->nullable();
            $table->timestampTz('poll_until')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
        });

        DB::statement('
            CREATE UNIQUE INDEX settlement_payments_matched_trx_id_unique
            ON settlement_payments (matched_trx_id)
            WHERE matched_trx_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS settlement_payments_matched_trx_id_unique');

        Schema::table('settlement_payments', fn (Blueprint $t) => $t->dropColumn([
            'auto_matched', 'matched_trx_id', 'matched_payer_name', 'matched_score',
            'matched_by_rule', 'poll_started_at', 'poll_until', 'poll_attempts',
        ]));
    }
};
