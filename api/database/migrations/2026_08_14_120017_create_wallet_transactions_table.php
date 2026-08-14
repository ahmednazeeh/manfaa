<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->index()->constrained('merchant_wallets');
            // Signed movement: positive tops up, negative funds a settlement.
            $table->bigInteger('amount_laari');
            $table->bigInteger('balance_after_laari');
            $table->char('currency', 3)->default('MVR');
            $table->string('type');
            $table->string('reference_type')->nullable();
            $table->bigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
