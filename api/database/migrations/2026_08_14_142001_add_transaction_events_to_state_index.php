<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_events', function (Blueprint $table) {
            // Payout eligibility derives each transaction's confirmation time
            // from the append-only event log (latest to_state = 'confirmed'
            // row) rather than a denormalised column; this index makes that
            // per-transaction lookup cheap.
            $table->index(['transaction_id', 'to_state']);
        });
    }

    public function down(): void
    {
        Schema::table('transaction_events', function (Blueprint $table) {
            $table->dropIndex(['transaction_id', 'to_state']);
        });
    }
};
