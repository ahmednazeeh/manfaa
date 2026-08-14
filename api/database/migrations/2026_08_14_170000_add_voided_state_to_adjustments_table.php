<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A reversal-memo adjustment is VOIDED when its transaction is later
        // reversed in place (the locking settlement was cancelled before the
        // credit ever applied): the in-place reverseAccrual fully mirrors the
        // accrual, so the memo must never net a future batch — that would
        // credit the merchant twice for one sale. Voiding keeps the row
        // (append-only history) while retiring the credit.
        DB::statement('ALTER TABLE adjustments DROP CONSTRAINT adjustments_state_check');
        DB::statement("ALTER TABLE adjustments ADD CONSTRAINT adjustments_state_check CHECK (state IN ('pending', 'applied', 'voided'))");

        Schema::table('adjustments', function (Blueprint $table) {
            $table->timestampTz('voided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropColumn('voided_at');
        });

        DB::statement('ALTER TABLE adjustments DROP CONSTRAINT adjustments_state_check');
        DB::statement("ALTER TABLE adjustments ADD CONSTRAINT adjustments_state_check CHECK (state IN ('pending', 'applied'))");
    }
};
