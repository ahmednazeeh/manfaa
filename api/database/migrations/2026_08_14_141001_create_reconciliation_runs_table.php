<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per daily reconciliation run (§12 Phase 1): the §5 journal
        // invariant plus derived-vs-ledger balances, recorded append-only.
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('ran_at')->index();
            $table->string('status');
            $table->bigInteger('journals_checked')->default(0);
            $table->jsonb('issues')->nullable();
            $table->jsonb('totals');
        });

        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_status_check CHECK (status IN ('ok', 'divergent'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_runs');
    }
};
