<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained();
            $table->bigInteger('balance_laari')->default(0);
            $table->char('currency', 3)->default('MVR');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_wallets');
    }
};
