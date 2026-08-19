<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BML is for WATCHING, never for sending (owner correction 2026-08-19).
 *
 * Everything we pay out — customers and merchants alike — leaves from MIB,
 * whatever bank the payee happens to bank with. `/bml/transfer` is never
 * called. BML exists here only so that a customer who chose BML at checkout,
 * or a merchant who settled into our BML account, can have their payment
 * matched against BML's own history.
 *
 * So `bank` goes and `history_only` replaces it. The column it replaces
 * existed to route a payout to the payee's own bank — a rule that is simply
 * not ours. A flag that says what a profile MAY do is also safer than one
 * that says what it is: this one can refuse a send, where a label could only
 * ever inform one.
 *
 * Dropped in the same round it shipped, with the switch still off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_profiles', function (Blueprint $table): void {
            $table->boolean('history_only')->default(false);
            $table->dropColumn('bank');
        });

        DB::table('transfer_profiles')
            ->where('segment', 'like', '%bml%')
            ->update(['history_only' => true]);
    }

    public function down(): void
    {
        Schema::table('transfer_profiles', function (Blueprint $table): void {
            $table->string('bank')->nullable();
            $table->dropColumn('history_only');
        });
    }
};
