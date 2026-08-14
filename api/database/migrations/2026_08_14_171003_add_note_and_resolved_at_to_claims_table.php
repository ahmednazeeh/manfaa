<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completes the claims row for the customer submission flow (§12 Phase 3):
     * the customer's own note travels separately from the admin's
     * resolution_note, and resolution gains a timestamp for SLA reporting.
     * Everything else the flow needs (claimed_date, claimed_amount_laari,
     * receipt_no, state, resolved_by, resulting_transaction_id) already
     * exists on the Phase 0 table.
     */
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->text('note')->nullable();
            $table->timestampTz('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['note', 'resolved_at']);
        });
    }
};
