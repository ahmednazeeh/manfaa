<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cap widening (PLAN §13b item 3): the §4 structural cashback ceiling rises
 * 1000 -> 2000 bp (10% -> 20%). The schema now admits the full structural
 * range; whether a rate is actually SELLABLE is governed separately by the
 * active fee tier schedule's ceiling (TierScheduleService::activeCeiling())
 * — with the seeded 50-1000 schedule still active, rates above 10% remain
 * refused with `rate_not_priced` until an admin publishes a wider table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE merchant_rates DROP CONSTRAINT merchant_rates_rate_bp_check');
        DB::statement('ALTER TABLE merchant_rates ADD CONSTRAINT merchant_rates_rate_bp_check CHECK (rate_bp BETWEEN 50 AND 2000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE merchant_rates DROP CONSTRAINT merchant_rates_rate_bp_check');
        DB::statement('ALTER TABLE merchant_rates ADD CONSTRAINT merchant_rates_rate_bp_check CHECK (rate_bp BETWEEN 50 AND 1000)');
    }
};
