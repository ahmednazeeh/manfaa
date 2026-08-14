<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cap widening (PLAN §13b item 3), promotions side: same 1000 -> 2000 bp
 * structural widening as merchant_rates. Sellability above the active fee
 * tier schedule's ceiling is still refused in the domain (rate_not_priced)
 * until the admin publishes a schedule that prices those rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE promotions DROP CONSTRAINT promotions_rate_bp_check');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_rate_bp_check CHECK (rate_bp BETWEEN 50 AND 2000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE promotions DROP CONSTRAINT promotions_rate_bp_check');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_rate_bp_check CHECK (rate_bp BETWEEN 50 AND 1000)');
    }
};
