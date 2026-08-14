<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches');
            $table->foreignId('customer_id')->nullable()->index()->constrained('customers');
            $table->foreignId('promotion_id')->nullable()->constrained();
            $table->string('origin');
            $table->string('invoice_no');
            $table->string('external_ref')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->bigInteger('eligible_laari');
            $table->bigInteger('sale_laari')->nullable();
            $table->char('currency', 3)->default('MVR');
            // Frozen at occurred_at — never re-resolved from rate history.
            $table->integer('rate_bp');
            $table->integer('fee_bp');
            $table->bigInteger('cashback_laari')->default(0);
            $table->bigInteger('fee_laari')->default(0);
            $table->bigInteger('fee_gst_laari')->default(0);
            $table->string('state')->default('tracked')->index();
            $table->string('reason_code')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->timestampTz('clock_start_at')->nullable();
            $table->timestampTz('due_at')->nullable()->index();
            $table->timestampsTz();

            $table->unique(['merchant_id', 'invoice_no']);
            // Idempotency is scoped to the merchant's own writes (§9.2) — two
            // merchants may legitimately derive the same key.
            $table->unique(['merchant_id', 'idempotency_key']);
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_origin_check CHECK (origin IN ('pos', 'manual', 'online_link', 'api_phone', 'card_linked'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_state_check CHECK (state IN ('tracked', 'awaiting_validation', 'payable_unfunded', 'on_hold', 'confirmed', 'paid', 'reversed', 'written_off'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
