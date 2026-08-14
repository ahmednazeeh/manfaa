<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 2 reversal-as-adjustment (§7 locked batches): an adjustment
        // created from a reversal snapshots the transaction's STORED
        // component integers, negated, so applying it later can post
        // Postings::reverseAccrual without ever recomputing from a rate.
        // state: pending (memo, no ledger) → applied (netted into a
        // settlement draft; reverseAccrual posted at that moment).
        Schema::table('adjustments', function (Blueprint $table) {
            $table->bigInteger('cashback_laari')->default(0);
            $table->bigInteger('fee_laari')->default(0);
            $table->bigInteger('fee_gst_laari')->default(0);
            $table->string('state')->default('pending')->index();
            $table->foreignId('settlement_id')->nullable()->constrained();
            $table->timestampTz('applied_at')->nullable();
        });

        DB::statement("ALTER TABLE adjustments ADD CONSTRAINT adjustments_state_check CHECK (state IN ('pending', 'applied'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE adjustments DROP CONSTRAINT adjustments_state_check');

        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settlement_id');
            $table->dropColumn(['cashback_laari', 'fee_laari', 'fee_gst_laari', 'state', 'applied_at']);
        });
    }
};
