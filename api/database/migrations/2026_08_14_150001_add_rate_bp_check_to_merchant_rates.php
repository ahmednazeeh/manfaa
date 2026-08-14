<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // §4: the fee tiers span 50–1000bp exactly — any rate outside that
        // range falls into no tier, so the schema refuses it outright.
        DB::statement('ALTER TABLE merchant_rates ADD CONSTRAINT merchant_rates_rate_bp_check CHECK (rate_bp BETWEEN 50 AND 1000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE merchant_rates DROP CONSTRAINT merchant_rates_rate_bp_check');
    }
};
