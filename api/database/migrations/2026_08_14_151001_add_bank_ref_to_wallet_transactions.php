<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Set on bank top-ups only; the unique pair mirrors the
            // settlement_payments idempotency rule so the same transfer can
            // never top a wallet up twice. Other movement types leave it
            // NULL and stay unconstrained (Postgres NULLs are distinct).
            $table->string('bank_ref')->nullable();
            $table->unique(['wallet_id', 'bank_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['wallet_id', 'bank_ref']);
            $table->dropColumn('bank_ref');
        });
    }
};
