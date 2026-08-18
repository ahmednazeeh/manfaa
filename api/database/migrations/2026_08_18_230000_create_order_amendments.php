<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP6 — a shop reducing an order, and what it then owes (§2.7, §5.4c).
 *
 * The amendment tables are an AUDIT TRAIL, not the source of truth: what the
 * shop will supply lives in `suborder_items.fulfilled_qty`, and these rows
 * record who changed it, when, and why. A shop that habitually cuts orders
 * should be visible to an admin rather than a matter of opinion.
 *
 * `customer_refunds` is the OBLIGATION. There is no customer wallet in this
 * platform — a payable balance is derived from confirmed cashback — so
 * rather than half-build one inside a fulfilment round, an amendment records
 * what is owed and MP8 decides how it is paid. The row is created in the
 * same transaction as the reduction, so an order can never be cut without
 * the debt existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suborder_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('suborder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_user_id')->nullable()->constrained('merchant_users')->nullOnDelete();
            $table->string('reason');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('refund_laari');
            $table->timestamps();

            $table->index('suborder_id');
        });

        DB::statement("
            ALTER TABLE suborder_amendments ADD CONSTRAINT amendment_reason_check
            CHECK (reason IN ('out_of_stock', 'damaged', 'customer_request', 'other'))
        ");

        Schema::create('suborder_amendment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('amendment_id')->constrained('suborder_amendments')->cascadeOnDelete();
            $table->foreignId('suborder_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty_before');
            $table->unsignedInteger('qty_after');
            $table->unsignedBigInteger('refund_laari');
            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE suborder_amendment_lines ADD CONSTRAINT amendment_reduces_only_check
            CHECK (qty_after < qty_before)
        ');

        Schema::create('customer_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suborder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('amendment_id')->nullable()->constrained('suborder_amendments')->nullOnDelete();

            $table->unsignedBigInteger('amount_laari');
            $table->string('reason');
            // pending until MP8 settles it. Deliberately NOT paid here: half
            // a wallet is worse than none.
            $table->string('state')->default('pending');
            $table->timestampTz('settled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'state']);
        });

        DB::statement("
            ALTER TABLE customer_refunds ADD CONSTRAINT refund_state_check
            CHECK (state IN ('pending', 'settled', 'cancelled'))
        ");
        DB::statement("
            ALTER TABLE customer_refunds ADD CONSTRAINT refund_reason_check
            CHECK (reason IN ('amendment', 'suborder_rejected', 'order_cancelled'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_refunds');
        Schema::dropIfExists('suborder_amendment_lines');
        Schema::dropIfExists('suborder_amendments');
    }
};
