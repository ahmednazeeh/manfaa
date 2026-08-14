<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->index()->constrained();
            $table->string('reference')->unique();
            $table->string('state')->default('draft')->index();
            $table->string('funding_method')->default('bank');
            $table->bigInteger('sale_total_laari')->default(0);
            $table->bigInteger('cashback_total_laari')->default(0);
            $table->bigInteger('fee_total_laari')->default(0);
            $table->bigInteger('fee_gst_total_laari')->default(0);
            $table->bigInteger('amount_due_laari')->default(0);
            $table->bigInteger('amount_received_laari')->default(0);
            $table->char('currency', 3)->default('MVR');
            $table->timestampTz('due_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_state_check CHECK (state IN ('draft', 'awaiting_payment', 'payment_review', 'settled', 'partially_settled', 'cancelled'))");
        DB::statement("ALTER TABLE settlements ADD CONSTRAINT settlements_funding_method_check CHECK (funding_method IN ('bank', 'wallet'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
