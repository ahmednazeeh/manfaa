<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the marketplace froze too little of (owner, 2026-08-22).
 *
 * 1. The store's MINIMUM ELIGIBLE SALE never applied to marketplace
 *    orders: a MVR 20 basket at a store with a MVR 50 minimum earned
 *    cashback online while the same sale at the till would not. The
 *    minimum is now frozen onto the suborder (like the rates) and applied
 *    at pricing and at every fulfilment re-pricing.
 *
 * 2. Each line's OWN rate. Checkout priced lines with category overrides
 *    (excluded = 0, category rate, else standing) but stored only the
 *    standing rate on the suborder, so a partial fulfilment re-priced
 *    every line at the standing rate — an excluded category started
 *    paying out the moment a shop dropped an item from the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suborders', function (Blueprint $table): void {
            $table->unsignedBigInteger('cashback_min_laari')->default(0)->after('cashback_rate_bp');
        });

        Schema::table('suborder_items', function (Blueprint $table): void {
            // Nullable for rows written before this migration: recompute
            // falls back to the suborder's standing rate for those, exactly
            // as it always did.
            $table->unsignedInteger('cashback_rate_bp')->nullable()->after('cashback_laari');
        });
    }

    public function down(): void
    {
        Schema::table('suborder_items', function (Blueprint $table): void {
            $table->dropColumn('cashback_rate_bp');
        });

        Schema::table('suborders', function (Blueprint $table): void {
            $table->dropColumn('cashback_min_laari');
        });
    }
};
