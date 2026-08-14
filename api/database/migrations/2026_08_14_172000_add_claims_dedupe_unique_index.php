<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One LIVE claim per (customer, merchant, receipt, date): open,
     * in-review and approved claims block an identical resubmission — the
     * race-proof backstop behind the app-layer dedupe check — while a
     * rejected claim leaves the way clear to correct and refile. Partial
     * index, so the append-only history of rejected claims is untouched.
     */
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX claims_live_dedupe_unique ON claims (customer_id, merchant_id, receipt_no, claimed_date) WHERE state <> 'rejected'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS claims_live_dedupe_unique');
    }
};
