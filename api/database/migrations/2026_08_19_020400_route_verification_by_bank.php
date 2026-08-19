<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read the history of the account the CUSTOMER paid into, and send from a
 * profile at the recipient's own bank (owner question 2026-08-19: "it polls
 * for customer/merchant selected bank during transfer?").
 *
 * It did not, and that was wrong. A customer picks BML or MIB at checkout —
 * `orders.payment_method` records which — and we hold an account at each.
 * Polling one fixed account would have left every order paid into the other
 * bank unmatched forever, silently, while the screen said auto-verify was
 * on.
 *
 * So the mapping lives on the platform account itself: the account a
 * customer was told to pay into is the thing that knows how its own history
 * is read. Nothing falls back to a global account — reading the WRONG bank's
 * history is worse than not reading any, because it can only produce a match
 * that means nothing.
 *
 * `transfer_profiles.bank` does the same for money going OUT. A payout row
 * already records the payee's bank; where we hold an account at that bank,
 * paying from it keeps the transfer inside one bank. Left null everywhere,
 * behaviour is exactly what it is today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_bank_accounts', function (Blueprint $table): void {
            $table->foreignId('verify_profile_id')
                ->nullable()
                // Not cascading: deleting a profile must not silently stop
                // an account being watched without anyone being told.
                ->constrained('transfer_profiles')
                ->nullOnDelete();
        });

        Schema::table('transfer_profiles', function (Blueprint $table): void {
            $table->string('bank')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_bank_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verify_profile_id');
        });

        Schema::table('transfer_profiles', fn (Blueprint $t) => $t->dropColumn('bank'));
    }
};
