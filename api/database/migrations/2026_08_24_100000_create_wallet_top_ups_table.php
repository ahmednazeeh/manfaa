<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant-initiated wallet top-ups (owner, 2026-08-24 — reverses PLAN §1
 * "wallet is not pre-funding").
 *
 * A merchant transfers to the platform account, uploads the slip and
 * optionally types the bank reference — the SAME receipt-first act a
 * settlement uses — and the row sits `pending` until the transfer is found
 * in the bank's own history (auto) or an admin matches it by hand. Only a
 * MATCH credits the wallet; the row itself is a claim, never money.
 *
 * Columns mirror settlement_payments deliberately: the verifier, the poll
 * jobs, the slip stream and the admin queue are siblings of the settlement
 * ones and read the same names.
 *
 * Two uniqueness rules, both the same shape as settlement_payments:
 *  - (merchant_id, bank_ref) partial-unique over non-rejected rows, so a
 *    double-tapped upload cannot claim one transfer twice, while a rejected
 *    reference can be re-submitted once the problem is sorted;
 *  - matched_trx_id partial-unique, the per-table half of "one bank credit
 *    funds one thing" — BankCreditClaim reads across all three tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_top_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->bigInteger('amount_laari');
            $table->char('currency', 3)->default('MVR');
            $table->foreignId('platform_bank_account_id')->nullable()->constrained();
            $table->string('bank_ref', 128)->nullable();

            $table->string('slip_path')->nullable();
            $table->string('slip_mime')->nullable();
            $table->bigInteger('slip_size_bytes')->nullable();
            $table->text('receipt_text')->nullable();
            $table->foreignId('uploaded_by')->nullable();

            $table->string('state')->default('pending');

            $table->boolean('auto_matched')->default(false);
            $table->string('matched_trx_id')->nullable();
            $table->json('matched_trx_refs')->nullable();
            $table->string('matched_payer_name')->nullable();
            $table->unsignedInteger('matched_score')->nullable();
            $table->string('matched_by_rule')->nullable();
            $table->foreignId('matched_by')->nullable();
            $table->timestampTz('matched_at')->nullable();
            // The wallet movement the match produced — the audit link from
            // claim to money.
            $table->foreignId('wallet_transaction_id')->nullable();

            $table->timestampTz('poll_started_at')->nullable();
            $table->timestampTz('poll_until')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);

            $table->foreignId('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->timestampsTz();

            $table->index(['merchant_id', 'state']);
        });

        DB::statement("ALTER TABLE wallet_top_ups ADD CONSTRAINT wallet_top_ups_state_check CHECK (state IN ('pending', 'matched', 'rejected'))");

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX wallet_top_ups_merchant_bank_ref_unique
            ON wallet_top_ups (merchant_id, bank_ref)
            WHERE bank_ref IS NOT NULL AND state <> 'rejected'
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX wallet_top_ups_matched_trx_id_unique
            ON wallet_top_ups (matched_trx_id)
            WHERE matched_trx_id IS NOT NULL
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_top_ups');
    }
};
