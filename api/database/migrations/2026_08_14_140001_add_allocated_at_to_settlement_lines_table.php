<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_lines', function (Blueprint $table) {
            // Oldest-first allocation (§7) confirms whole lines only. A null
            // means the line is still waiting for merchant money; a timestamp
            // records exactly when the payment covered it.
            $table->timestampTz('allocated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->dropColumn('allocated_at');
        });
    }
};
