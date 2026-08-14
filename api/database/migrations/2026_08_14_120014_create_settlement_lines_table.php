<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot of the transaction's money at batch time; frozen once the
        // settlement leaves draft.
        Schema::create('settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->index()->constrained();
            $table->foreignId('transaction_id')->index()->constrained();
            $table->bigInteger('cashback_laari');
            $table->bigInteger('fee_laari');
            $table->bigInteger('fee_gst_laari');
            $table->char('currency', 3)->default('MVR');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_lines');
    }
};
