<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backdated credits (PLAN §1 "Backdated credits"): a sale older than the
     * merchant's validation window skips on_hold entirely — it goes straight
     * to payable_unfunded with the 15-day clock starting NOW — and the
     * merchant/vendor can never reverse it (admin adjustment only).
     *
     * That irreversibility is a permanent property of the ROW, so it lives in
     * a column rather than being inferred from reason_code: reason_code is
     * rewritten by later transitions (a hold, a write-off), and a rule that
     * says "this can never be reversed" must not be erasable by an unrelated
     * hop. Existing rows are false — the old stale path put them on_hold, and
     * a hold is reviewable and reversible by design.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('backdated')->default(false)->after('reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('backdated');
        });
    }
};
