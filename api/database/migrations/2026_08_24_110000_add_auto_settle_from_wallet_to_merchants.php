<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-settlement from the wallet (owner, 2026-08-24 — reverses PLAN §1
 * "wallet is not pre-funding"): when the merchant's wallet holds balance,
 * the hourly run settles their validated cashback from it, oldest first,
 * as far as the balance reaches. This is the merchant's switch for that.
 *
 * Default ON, and backfilled ON for every existing store: a merchant who
 * tops up a wallet has already said what the money is for, and the run
 * only ever spends what a top-up put there. A store that would rather
 * settle by hand flips it off on the wallet screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->boolean('auto_settle_from_wallet')->default(true)->after('settlement_method');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropColumn('auto_settle_from_wallet');
        });
    }
};
