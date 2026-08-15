<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Curated featured offers — the image banners at the top of Discover.
     *
     * An offer ALWAYS belongs to a merchant, and deliberately stores none of
     * that merchant's facts. The cashback percentage, the logo, the category
     * and whether the store is trading at all are read live at render time,
     * so a banner can never advertise a rate the store has moved off or a
     * shop that has been suspended — the single failure mode a promotional
     * surface has. What the offer owns is only what a merchant record cannot
     * express: the artwork, the words, and when it runs.
     *
     * Distinct from `promotions`, which change what a sale EARNS. An offer
     * changes nothing about pricing; it is editorial placement.
     */
    public function up(): void
    {
        Schema::create('store_offers', function (Blueprint $table) {
            $table->id();
            // Cascade: an offer for a deleted merchant is not an offer.
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->string('title', 120);
            $table->string('title_dv', 120)->nullable();
            $table->string('blurb', 240)->nullable();
            $table->string('blurb_dv', 240)->nullable();
            /** The pill above the title — "Limited time", "New". */
            $table->string('badge', 40)->nullable();
            $table->string('badge_dv', 40)->nullable();

            $table->string('image_path')->nullable();

            /**
             * The window this offer runs for. Both null = runs until it is
             * deactivated, which is the ordinary case for an evergreen
             * banner; an end date is how a seasonal one retires itself
             * without anyone remembering to.
             */
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            $table->integer('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            // The public read is "live offers, in curated order".
            $table->index(['active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_offers');
    }
};
