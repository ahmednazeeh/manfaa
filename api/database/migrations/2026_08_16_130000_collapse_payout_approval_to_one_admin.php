<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // There is one admin account, so a gate demanding two distinct
        // approvers can never be satisfied — and a control nobody can pass
        // is not a control. The pair collapses to a single approval stamp.
        Schema::table('payout_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_second');
            $table->dropColumn('second_approved_at');
        });

        Schema::table('payout_batches', function (Blueprint $table) {
            $table->renameColumn('approved_by_first', 'approved_by');
            $table->renameColumn('first_approved_at', 'approved_at');
        });

        // Postgres carries a foreign key's name through a column rename, so
        // without this the constraint stays payout_batches_approved_by_first_foreign
        // and a later dropConstrainedForeignId('approved_by') looks for a
        // name that does not exist.
        DB::statement('ALTER TABLE payout_batches RENAME CONSTRAINT payout_batches_approved_by_first_foreign TO payout_batches_approved_by_foreign');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payout_batches RENAME CONSTRAINT payout_batches_approved_by_foreign TO payout_batches_approved_by_first_foreign');

        Schema::table('payout_batches', function (Blueprint $table) {
            $table->renameColumn('approved_by', 'approved_by_first');
            $table->renameColumn('approved_at', 'first_approved_at');
        });

        Schema::table('payout_batches', function (Blueprint $table) {
            $table->foreignId('approved_by_second')->nullable()->constrained('admin_users');
            $table->timestampTz('second_approved_at')->nullable();
        });
    }
};
