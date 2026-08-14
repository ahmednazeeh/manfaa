<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_batches', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('admin_users');
            // Dual approval evidence: who approved is on approved_by_first /
            // approved_by_second (Phase 0 columns); when they did is here.
            $table->timestampTz('first_approved_at')->nullable();
            $table->timestampTz('second_approved_at')->nullable();
            $table->timestampTz('exported_at')->nullable();
        });

        // A draft batch is rebuilt by cancel + recreate only, so 'cancelled'
        // joins the state list and the reference stays unique among the
        // batches that still count (a recreated PB-2026-08 must be allowed).
        DB::statement('ALTER TABLE payout_batches DROP CONSTRAINT payout_batches_state_check');
        DB::statement("ALTER TABLE payout_batches ADD CONSTRAINT payout_batches_state_check CHECK (state IN ('draft', 'approved', 'processing', 'sent', 'completed', 'partially_failed', 'cancelled'))");
        DB::statement('ALTER TABLE payout_batches DROP CONSTRAINT payout_batches_reference_unique');
        DB::statement("CREATE UNIQUE INDEX payout_batches_reference_active_unique ON payout_batches (reference) WHERE state <> 'cancelled'");

        Schema::table('payout_items', function (Blueprint $table) {
            // Completes the bank snapshot taken from the customer at build
            // time (bank + account exist since Phase 0), and records the
            // bank's own reference from the result file import.
            $table->string('account_name')->nullable();
            $table->string('bank_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payout_items', function (Blueprint $table) {
            $table->dropColumn(['account_name', 'bank_reference']);
        });

        DB::statement('DROP INDEX payout_batches_reference_active_unique');
        DB::statement('ALTER TABLE payout_batches ADD CONSTRAINT payout_batches_reference_unique UNIQUE (reference)');
        DB::statement('ALTER TABLE payout_batches DROP CONSTRAINT payout_batches_state_check');
        DB::statement("ALTER TABLE payout_batches ADD CONSTRAINT payout_batches_state_check CHECK (state IN ('draft', 'approved', 'processing', 'sent', 'completed', 'partially_failed'))");

        Schema::table('payout_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['first_approved_at', 'second_approved_at', 'exported_at']);
        });
    }
};
