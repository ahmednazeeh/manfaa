<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->index()->constrained('ledger_journals');
            $table->foreignId('account_id')->index()->constrained('ledger_accounts');
            $table->bigInteger('debit_laari')->default(0);
            $table->bigInteger('credit_laari')->default(0);
            $table->char('currency', 3)->default('MVR');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_non_negative_check CHECK (debit_laari >= 0 AND credit_laari >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_single_side_check CHECK (debit_laari = 0 OR credit_laari = 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
