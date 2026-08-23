<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer referral programme (owner, 2026-08-23).
 *
 * A customer's existing 6-digit customer_code IS their referral code. A new
 * customer may enter one at signup — recorded here, immutable after — and
 * when their cumulative validated spend crosses the configurable threshold,
 * the REFERRER earns a configurable bonus into their wallet, once per
 * referred customer, ever.
 *
 * `referral_rewarded_at` lives on the REFERRED customer: it is stamped the
 * moment their referrer is paid, so "who still counts toward a bonus" is one
 * indexed NULL check instead of a wallet-ledger join.
 *
 * The wallet entry CHECK gains 'referral' — the bonus is a first-class entry
 * type in the customer's ledger, not an 'adjustment' pretending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // nullOnDelete: deleting the referrer's account must not delete
            // — or fail on — the customers they brought in.
            $table->foreignId('referred_by_customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->timestampTz('referred_at')->nullable();
            $table->timestampTz('referral_rewarded_at')->nullable();

            // The daily safety-net scan and the referrer's friends list both
            // filter on this; Postgres does not index FK columns by itself.
            $table->index('referred_by_customer_id');
        });

        DB::statement('ALTER TABLE customer_wallet_entries DROP CONSTRAINT wallet_entry_type_check');
        DB::statement("
            ALTER TABLE customer_wallet_entries ADD CONSTRAINT wallet_entry_type_check
            CHECK (type IN ('refund', 'withdrawal', 'withdrawal_reversed', 'adjustment', 'referral'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer_wallet_entries DROP CONSTRAINT wallet_entry_type_check');
        DB::statement("
            ALTER TABLE customer_wallet_entries ADD CONSTRAINT wallet_entry_type_check
            CHECK (type IN ('refund', 'withdrawal', 'withdrawal_reversed', 'adjustment'))
        ");

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['referred_by_customer_id']);
            $table->dropConstrainedForeignId('referred_by_customer_id');
            $table->dropColumn(['referred_at', 'referral_rewarded_at']);
        });
    }
};
