<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->index()->constrained();
            $table->bigInteger('amount_laari');
            $table->char('currency', 3)->default('MVR');
            $table->string('method');
            $table->string('bank_ref')->nullable();
            $table->string('slip_path')->nullable();
            $table->string('state')->default('pending');
            $table->foreignId('matched_by')->nullable();
            $table->timestampTz('matched_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE settlement_payments ADD CONSTRAINT settlement_payments_state_check CHECK (state IN ('pending', 'matched', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_payments');
    }
};
