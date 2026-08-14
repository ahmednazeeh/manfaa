<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Set by settlement allocation the moment the transaction moves
            // payable_unfunded → confirmed. Evidence of when the merchant's
            // money actually covered this reward.
            $table->timestampTz('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
