<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            // The database is the authority on duplicate transfers: the same
            // bank reference can only ever be recorded once per settlement,
            // so a double-submitted slip can never book cash twice. NULL
            // bank_refs stay unconstrained (Postgres treats NULLs as
            // distinct), matching the nullable column.
            $table->unique(['settlement_id', 'bank_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('settlement_payments', function (Blueprint $table) {
            $table->dropUnique(['settlement_id', 'bank_ref']);
        });
    }
};
