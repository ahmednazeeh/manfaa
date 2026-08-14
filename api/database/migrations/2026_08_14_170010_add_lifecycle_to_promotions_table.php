<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 promotions engine (PLAN §12, original spec §9): the base table
 * from Phase 0 already carries merchant/branch scope, rate, window, minimum
 * purchase, per-customer cap and status — this adds only what the lifecycle
 * needs: who drafted it, when it was published/cancelled, the §4 rate-range
 * check, window sanity checks, and the composite index the sale-time
 * resolver reads (published promos covering an instant for a merchant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('merchant_users');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->index(['merchant_id', 'status', 'starts_at', 'ends_at'], 'promotions_resolution_index');
        });

        // §4: integer basis points 50–1000, or 4.995% falls into no fee tier.
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_rate_bp_check CHECK (rate_bp BETWEEN 50 AND 1000)');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_window_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_min_purchase_check CHECK (min_purchase_laari IS NULL OR min_purchase_laari >= 0)');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_cap_check CHECK (max_cashback_per_customer_laari IS NULL OR max_cashback_per_customer_laari > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promotions DROP CONSTRAINT IF EXISTS promotions_cap_check');
        DB::statement('ALTER TABLE promotions DROP CONSTRAINT IF EXISTS promotions_min_purchase_check');
        DB::statement('ALTER TABLE promotions DROP CONSTRAINT IF EXISTS promotions_window_check');
        DB::statement('ALTER TABLE promotions DROP CONSTRAINT IF EXISTS promotions_rate_bp_check');

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex('promotions_resolution_index');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['published_at', 'cancelled_at']);
        });
    }
};
