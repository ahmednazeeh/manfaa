<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only tables (§5): a posted journal is never edited, so an
        // updated_at column is a standing invitation to mutate history.
        Schema::table('ledger_journals', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_journals', function (Blueprint $table) {
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->timestampTz('updated_at')->nullable();
        });
    }
};
