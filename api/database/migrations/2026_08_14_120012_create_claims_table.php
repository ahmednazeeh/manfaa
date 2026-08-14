<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->index()->constrained();
            $table->foreignId('customer_id')->index()->constrained();
            $table->date('claimed_date');
            $table->bigInteger('claimed_amount_laari');
            $table->char('currency', 3)->default('MVR');
            $table->string('receipt_no')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('state')->default('open');
            $table->foreignId('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resulting_transaction_id')->nullable()->constrained('transactions');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_state_check CHECK (state IN ('open', 'in_review', 'approved', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
