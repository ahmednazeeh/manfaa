<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP9 — the customer wallet, and the queue for money leaving the platform
 * (owner decision 2026-08-19).
 *
 * Until now a customer's balance was DERIVED — BalanceQuery sums confirmed
 * cashback on every read — so there was nowhere to put a refund. The wallet
 * is a real stored balance with a real ledger, built the way
 * `merchant_wallets` already is: a balance column plus one entry per
 * movement, never a bare number that can quietly drift from its history.
 *
 * The wallet is the HUB for money owed to a customer; `customer_payouts` is
 * the queue for money LEAVING to a bank. That split is what lets the bank
 * API arrive later as a worker rather than a redesign: today an admin works
 * the queue by hand, tomorrow a job drains it, and nothing else changes.
 *
 * CASHBACK PAYOUTS ARE DELIBERATELY UNTOUCHED. EligibilityQuery →
 * PayoutBatchBuilder → xlsx → bank is live, reconciled and trusted. The
 * wallet starts with refunds only; cashback may join it one day, and nothing
 * here blocks that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance_laari')->default(0);
            $table->string('currency', 3)->default('MVR');
            $table->timestamps();
        });

        // A wallet may never go negative: money we do not owe cannot be
        // withdrawn, and an overdraft on a customer balance is a bug that
        // pays somebody twice.
        DB::statement('ALTER TABLE customer_wallets ADD CONSTRAINT wallet_never_negative_check CHECK (balance_laari >= 0)');

        Schema::create('customer_wallet_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('customer_wallets')->cascadeOnDelete();
            // Signed: a credit is positive, a withdrawal negative. The
            // balance is the sum of these, and `balance_after_laari` is what
            // makes that checkable without replaying the whole history.
            $table->bigInteger('amount_laari');
            $table->bigInteger('balance_after_laari');
            $table->string('type');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement("
            ALTER TABLE customer_wallet_entries ADD CONSTRAINT wallet_entry_type_check
            CHECK (type IN ('refund', 'withdrawal', 'withdrawal_reversed', 'adjustment'))
        ");

        Schema::create('customer_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_laari');
            $table->string('currency', 3)->default('MVR');

            // Snapshotted at request time. A customer editing their bank
            // details afterwards must not redirect a transfer already in a
            // queue — the sheet, or the API call, has to say what it said
            // when a human approved it.
            $table->string('bank')->nullable();
            $table->string('account');
            $table->string('account_name')->nullable();

            /*
             * THE IDEMPOTENCY KEY, and the reason this column is unique.
             *
             * It is sent to the bank as `internal_ref`, and the upstream
             * treats a repeat as a duplicate rather than a second payment.
             * A stable business key we can regenerate is what makes a retry
             * safe: the same payout always produces the same string, so a
             * crashed worker resuming cannot pay twice.
             */
            $table->string('internal_ref')->unique();

            $table->string('state')->default('pending');
            $table->unsignedInteger('attempts')->default(0);

            // What the bank gave back. `trx_id` is a transaction reference;
            // `approval_id` is NOT — it is a queue record id for a transfer
            // parked awaiting dual control, and confusing the two would mean
            // reporting an unmade payment as made.
            $table->string('trx_id')->nullable();
            $table->string('approval_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestampTz('requested_at');
            $table->timestampTz('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('admin_users');
            $table->timestamps();

            $table->index(['state', 'requested_at']);
            $table->index('customer_id');
        });

        DB::statement("
            ALTER TABLE customer_payouts ADD CONSTRAINT customer_payout_state_check
            CHECK (state IN ('pending', 'processing', 'pending_approval', 'sent', 'failed', 'cancelled'))
        ");

        DB::statement('ALTER TABLE customer_payouts ADD CONSTRAINT customer_payout_amount_check CHECK (amount_laari > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payouts');
        Schema::dropIfExists('customer_wallet_entries');
        Schema::dropIfExists('customer_wallets');
    }
};
