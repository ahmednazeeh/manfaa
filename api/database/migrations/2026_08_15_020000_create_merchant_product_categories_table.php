<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-store PRODUCT categories (§1 decision 2026-08-15, Task #25) —
     * distinct from the superadmin-curated STORE categories. Each row is
     * either `excluded` (lines in it earn nothing, even during promotions)
     * or a `rate` override in basis points. The slug is generated from
     * name_en at creation and is IMMUTABLE thereafter — it is the public
     * line key vendors submit, and transaction_lines snapshot it.
     *
     * rate_bp is present exactly when mode = 'rate' (DB CHECK), bounded
     * 50..2000 structurally; the application additionally refuses rates
     * above the active fee tier schedule's ceiling (rate_not_priced), same
     * as the standing rate path.
     *
     * Deactivation is soft (active = false): historical transaction lines
     * keep their snapshots; there is no delete path.
     */
    public function up(): void
    {
        Schema::create('merchant_product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->string('slug', 80);
            $table->string('name_en', 120);
            $table->string('name_dv', 120)->nullable();
            $table->string('mode', 16);
            $table->integer('rate_bp')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort')->default(0);
            $table->timestampsTz();

            $table->unique(['merchant_id', 'slug']);
        });

        DB::statement("ALTER TABLE merchant_product_categories ADD CONSTRAINT merchant_product_categories_mode_check CHECK (mode IN ('excluded', 'rate'))");
        DB::statement('ALTER TABLE merchant_product_categories ADD CONSTRAINT merchant_product_categories_rate_bp_range_check CHECK (rate_bp IS NULL OR (rate_bp >= 50 AND rate_bp <= 2000))');
        // A rate override carries a rate; an exclusion never does.
        DB::statement("ALTER TABLE merchant_product_categories ADD CONSTRAINT merchant_product_categories_mode_rate_check CHECK ((mode = 'rate') = (rate_bp IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_product_categories');
    }
};
