<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP8 — marketplace cashback joins the one cashback engine (§5.3).
 *
 * One wallet, one Activity feed, one payout: a shopper should not have two
 * kinds of cashback with two sets of rules. So a marketplace reward is a
 * `transactions` row like any other, with a new origin and a link back to
 * the suborder that earned it.
 *
 * TWO THINGS ARE DIFFERENT, and both matter to the money:
 *
 * 1. `fee_laari` is ZERO. The platform's cut of a marketplace order is the
 *    marketplace fee, already deducted from what we pay the merchant. Billing
 *    the standard cashback fee on top would charge them twice for one sale.
 *
 * 2. It is never a merchant RECEIVABLE. In a till sale the merchant owes us
 *    the cashback they granted and settles it monthly; in a marketplace
 *    order we are already holding the customer's money and deduct the
 *    cashback before paying the merchant. SettlementBuilder therefore
 *    excludes this origin — see eligibleTransactions().
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_origin_check');
        DB::statement("
            ALTER TABLE transactions ADD CONSTRAINT transactions_origin_check
            CHECK (origin IN ('pos', 'manual', 'online_link', 'api_phone', 'card_linked', 'claim', 'marketplace'))
        ");

        Schema::table('transactions', function (Blueprint $table): void {
            // Which suborder earned it. Nullable because every transaction
            // that predates the marketplace has none, and unique because one
            // suborder earns cashback exactly once — the constraint is what
            // makes crediting idempotent under a retried job.
            $table->foreignId('suborder_id')->nullable()->after('promotion_id')
                ->constrained()->nullOnDelete();
            $table->unique('suborder_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(['suborder_id']);
            $table->dropConstrainedForeignId('suborder_id');
        });

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT transactions_origin_check');
        DB::statement("
            ALTER TABLE transactions ADD CONSTRAINT transactions_origin_check
            CHECK (origin IN ('pos', 'manual', 'online_link', 'api_phone', 'card_linked', 'claim'))
        ");
    }
};
