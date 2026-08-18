<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MP4 — the cart (PLAN-marketplace.md §2.6).
 *
 * Server-side, because the cart PRICES ITSELF and pricing is our job.
 * Delivery thresholds, per-branch minimums and cashback projections all have
 * to agree with what checkout will charge; a cart computed on the client
 * would be a second opinion waiting to disagree with the till.
 *
 * An item is keyed on the LISTING (`branch_products`), not the product,
 * because the listing is the thing being bought: one shop's price for one
 * item. That also makes the multi-vendor grouping fall out for free — the
 * subcarts in `Cart Page Collapsible By Merchant.png` are just the items
 * grouped by their listing's branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty');

            // The price when it went in. NOT what checkout charges — the
            // cart re-prices from the live listing on every read — but
            // keeping it is what lets the screen say "this went up while it
            // was in your basket" instead of quietly changing the number.
            $table->unsignedBigInteger('unit_price_laari');

            $table->timestamps();

            $table->unique(['cart_id', 'branch_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
