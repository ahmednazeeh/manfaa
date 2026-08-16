<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One ACTIVE account per bank, enforced where it cannot be argued
        // with. The old shape allowed any number of accounts with exactly one
        // flagged primary, which answered "where does everyone send money"
        // but not "which of our banks is this". A merchant choosing between
        // two BML accounts is a merchant who can send to the wrong one.
        //
        // Partial, on `active` only: a retired account keeps its row so the
        // settlements that quote it still resolve, and its bank is free for a
        // replacement — which is the create-new-then-deactivate dance the
        // service already requires, since account numbers are immutable.
        DB::statement('CREATE UNIQUE INDEX platform_bank_accounts_active_bank_unique ON platform_bank_accounts (bank_name) WHERE active');

        Schema::table('settlements', function (Blueprint $table) {
            // WHICH account the merchant paid into. Previously unanswerable:
            // the instructions were rendered from whichever account was
            // primary when the screen loaded, so a settlement submitted
            // either side of a primary change could not be reconciled to a
            // statement afterwards. Nullable because rows written before this
            // column existed genuinely do not know.
            $table->foreignId('platform_bank_account_id')
                ->nullable()
                ->after('funding_method')
                ->constrained('platform_bank_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('platform_bank_account_id');
        });

        DB::statement('DROP INDEX IF EXISTS platform_bank_accounts_active_bank_unique');
    }
};
