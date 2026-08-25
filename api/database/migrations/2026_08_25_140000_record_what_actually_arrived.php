<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE TYPED FIGURE IS A CLAIM; THE BANK CREDIT IS THE FACT (owner,
 * 2026-08-25, from wallet top-up #2 in production).
 *
 * A merchant typed MVR 20.00, their slip and BML both said MVR 10.00
 * (BLAZ204399156496). Both verifiers required the bank credit to EQUAL the
 * typed amount, so they polled the whole window, matched nothing, and parked
 * a perfectly good transfer in the admin queue. From now on the EVIDENCE
 * (the reference typed, or the one read off their own slip) says WHICH
 * transfer is theirs, and the bank row then says how much arrived.
 *
 * Which means two figures now exist for one transfer, and both have to
 * survive: an auditor looking at a discrepancy needs to see what the
 * merchant said as well as what the bank did.
 *
 * ONE SHAPE, BOTH TABLES:
 *
 *   `amount_laari`   — unchanged and IMMUTABLE: what the merchant typed.
 *                      Never rewritten by a match, on either table.
 *   `received_laari` — NEW, nullable: what the bank actually credited,
 *                      stamped at match time from the matched row's own
 *                      amount (never from OCR — OCR is evidence, the
 *                      statement is the money). Null on a pending or
 *                      rejected row, and on a settlement payment an admin
 *                      matched by hand with no figure to hand: the readers
 *                      fall back to the claim there, which is the
 *                      behaviour those rows already had.
 *
 * Rather than a `claimed_*` rename: `amount_laari` is read by the funding
 * stack, three resources, two apps and the admin panel, it ALREADY holds
 * exactly the claim, and renaming a live money column to add a nullable
 * sibling would be the riskier half of the change for no extra truth.
 *
 * BACKFILL: every row already `matched` was matched UNDER the equality
 * rule, so the bank credited exactly the claim. Recording that is not a
 * guess — it is the only value those matches could have had — and it means
 * `received_laari` reads as "what arrived" for the whole history rather
 * than only for rows matched after today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_top_ups', function (Blueprint $table): void {
            $table->bigInteger('received_laari')->nullable()->after('amount_laari');
        });

        Schema::table('settlement_payments', function (Blueprint $table): void {
            $table->bigInteger('received_laari')->nullable()->after('amount_laari');
        });

        // The claim and the fact, named on the columns themselves — this is
        // the pair an auditor asks about, and psql is where they will look.
        DB::statement("COMMENT ON COLUMN wallet_top_ups.amount_laari IS 'The merchant''s CLAIM: what they typed. Immutable.'");
        DB::statement("COMMENT ON COLUMN wallet_top_ups.received_laari IS 'What the bank actually credited, from the matched statement row. Null until matched.'");
        DB::statement("COMMENT ON COLUMN settlement_payments.amount_laari IS 'The merchant''s CLAIM: what they typed. Immutable.'");
        DB::statement("COMMENT ON COLUMN settlement_payments.received_laari IS 'What the bank actually credited, from the matched statement row. Null until matched, and on hand-matched rows where no figure was stated.'");

        DB::table('wallet_top_ups')
            ->where('state', 'matched')
            ->whereNull('received_laari')
            ->update(['received_laari' => DB::raw('amount_laari')]);

        DB::table('settlement_payments')
            ->where('state', 'matched')
            ->whereNull('received_laari')
            ->update(['received_laari' => DB::raw('amount_laari')]);
    }

    public function down(): void
    {
        Schema::table('wallet_top_ups', function (Blueprint $table): void {
            $table->dropColumn('received_laari');
        });

        Schema::table('settlement_payments', function (Blueprint $table): void {
            $table->dropColumn('received_laari');
        });
    }
};
