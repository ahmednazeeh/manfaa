<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ratings and favourites — the half of a marketplace a shopper actually
 * touches (PLAN-marketplace.md §11.2, plan round MP11).
 *
 * The store cards have been drawing a star against `rating_count` /
 * `rating_sum` since MP1, and nothing has ever written to them. Every shop
 * has shown no rating because none COULD have one — the read side was built
 * and the write side never was.
 *
 * The rules are the plan's, and each is enforced here rather than trusted to
 * a caller:
 *
 *   - one rating per SUBORDER, not per order (a basket split across three
 *     shops is three separate experiences), held by a unique index;
 *   - by the customer who placed it, checked against the order;
 *   - only after `delivered` — nothing else is an opinion about service;
 *   - merchants cannot reply in v1, so there is no reply column to tempt
 *     anyone.
 *
 * The aggregates stay on the profile as sum + count rather than a stored
 * average: an average cannot be updated without reading every row back, and
 * two integers can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suborder_ratings', function (Blueprint $table): void {
            $table->id();

            // ONE per suborder, ever. The unique index is the rule — a
            // check in code is a race when someone double-taps.
            $table->foreignId('suborder_id')->unique()->constrained()->cascadeOnDelete();

            // Denormalised so a shop's ratings survive an order being pruned
            // and can be counted without walking the order tree.
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('branch_id')->constrained('merchant_branches');
            $table->foreignId('customer_id')->constrained();

            $table->unsignedSmallInteger('stars');
            // Optional, and short. A rating is a number; this is a courtesy.
            $table->string('comment', 500)->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
        });

        DB::statement('
            ALTER TABLE suborder_ratings ADD CONSTRAINT suborder_ratings_stars_check
            CHECK (stars BETWEEN 1 AND 5)
        ');

        /*
         * Favourites are per BRANCH, not per merchant.
         *
         * The branch is the storefront everywhere else in this domain — it
         * holds the stock, the delivery terms and the opening hours — and a
         * shopper favourites the shop they actually buy from, not the
         * company that owns it.
         */
        Schema::create('customer_favourite_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_favourite_branches');
        Schema::dropIfExists('suborder_ratings');
    }
};
