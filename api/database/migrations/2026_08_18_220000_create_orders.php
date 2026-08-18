<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP5 — orders (PLAN-marketplace.md §2.6).
 *
 * TWO levels, because the customer pays once and several shops fulfil
 * separately (`Order Received.png`): an `order` is the PAYMENT, a `suborder`
 * is the unit of FULFILMENT — one per shop, each accepted, prepared and
 * handed over on its own clock.
 *
 * Everything money-shaped is frozen at placement. The rates on a suborder
 * are the rates that were true when the customer agreed to them; a platform
 * fee change next week must never restate an order already being picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Null for an all-pickup order: there is nowhere to deliver.
            $table->foreignId('address_id')->nullable()->constrained('customer_addresses')->nullOnDelete();
            // The address AS IT WAS. An address edited or deleted later must
            // not rewrite where last month's order went.
            $table->json('address_snapshot')->nullable();

            $table->unsignedBigInteger('items_laari');
            $table->unsignedBigInteger('delivery_laari');
            $table->unsignedBigInteger('total_payable_laari');
            // Projected at placement; each suborder credits its own share
            // when the shop validates it (§5.3).
            $table->unsignedBigInteger('cashback_total_laari');

            $table->string('payment_method');
            $table->string('payment_state')->default('awaiting_proof');
            $table->string('receipt_path')->nullable();
            $table->timestampTz('proof_submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('admin_users');
            $table->timestampTz('verified_at')->nullable();
            $table->text('refused_reason')->nullable();

            $table->string('state')->default('placed');
            $table->timestampTz('placed_at');
            $table->timestamps();

            $table->index(['customer_id', 'state']);
            $table->index('payment_state');
        });

        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT order_payment_state_check
            CHECK (payment_state IN ('awaiting_proof', 'proof_submitted', 'verified', 'refused'))
        ");
        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT order_state_check
            CHECK (state IN ('placed', 'under_review', 'partly_confirmed', 'confirmed', 'completed', 'cancelled'))
        ");

        Schema::create('suborders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();
            // Which SHOP fulfils it. The branch is the storefront, so this
            // is not a detail of the merchant — it is the merchant.
            $table->foreignId('branch_id')->constrained('merchant_branches');
            $table->string('reference')->unique();

            $table->string('fulfilment');

            $table->unsignedBigInteger('items_laari');
            $table->unsignedBigInteger('delivery_laari');
            $table->unsignedBigInteger('subtotal_laari');

            // FROZEN at placement, every one of them.
            $table->unsignedInteger('cashback_rate_bp');
            $table->unsignedBigInteger('cashback_laari');
            $table->unsignedInteger('order_fee_bp');
            $table->unsignedBigInteger('order_fee_laari');
            $table->unsignedBigInteger('order_fee_gst_laari')->default(0);
            $table->unsignedBigInteger('payable_to_merchant_laari');

            $table->string('state')->default('new');
            $table->text('reject_reason')->nullable();
            $table->string('pickup_code')->nullable();

            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'state']);
            $table->index(['branch_id', 'state']);
        });

        DB::statement("
            ALTER TABLE suborders ADD CONSTRAINT suborder_state_check
            CHECK (state IN ('new', 'accepted', 'preparing', 'ready', 'out_for_delivery', 'delivered', 'rejected', 'cancelled'))
        ");
        DB::statement("
            ALTER TABLE suborders ADD CONSTRAINT suborder_fulfilment_check
            CHECK (fulfilment IN ('delivery', 'pickup'))
        ");

        Schema::create('suborder_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('suborder_id')->constrained()->cascadeOnDelete();
            // Kept for the merchant's own reporting; the SNAPSHOT below is
            // what the order actually says, so a renamed or archived product
            // cannot rewrite history.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->unsignedBigInteger('unit_price_laari');

            // What was ORDERED — immutable (§2.7).
            $table->unsignedInteger('qty');
            // What the shop will actually supply. Equal to qty until an
            // amendment says otherwise; the gap between them IS the
            // amendment, which is what lets the customer see the change.
            $table->unsignedInteger('fulfilled_qty');

            $table->unsignedBigInteger('line_total_laari');
            $table->unsignedBigInteger('cashback_laari');
            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE suborder_items ADD CONSTRAINT suborder_item_fulfilled_check
            CHECK (fulfilled_qty <= qty)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('suborder_items');
        Schema::dropIfExists('suborders');
        Schema::dropIfExists('orders');
    }
};
