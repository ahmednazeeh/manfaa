<?php

use App\Domain\Settlement\SettlementState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transaction may sit on at most ONE settlement, enforced by the
     * database — the application-level NOT EXISTS guard alone loses a race
     * when two drafts are built concurrently (the FOR UPDATE lock on the
     * transactions row does not re-run the subquery for an unmodified row,
     * so both workers can see the transaction as unclaimed and double-invoice
     * it). Cancellation deletes a batch's lines (nothing on them was ever
     * allocated), so "has a line row" is exactly "is claimed by a live
     * settlement" and a plain unique index is sufficient.
     */
    public function up(): void
    {
        // Lines left behind by settlements cancelled before this rule: the
        // claim was already released logically; drop the rows so the unique
        // index can build.
        DB::table('settlement_lines')
            ->whereIn('settlement_id', function ($query) {
                $query->select('id')
                    ->from('settlements')
                    ->where('state', SettlementState::Cancelled->value);
            })
            ->delete();

        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->unique('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_lines', function (Blueprint $table) {
            $table->dropUnique(['transaction_id']);
        });
    }
};
