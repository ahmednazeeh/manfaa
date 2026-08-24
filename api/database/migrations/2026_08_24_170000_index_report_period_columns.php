<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The three columns the superadmin reports periodise on (owner, 2026-08-24).
 *
 * Every one of the three reports drives on a timestamp range and not one of
 * the columns was indexed, so EXPLAIN on the live database answered each
 * count with a sequential scan of the largest tables in the schema:
 *
 *   cashback     transactions.occurred_at       Seq Scan on transactions
 *   cashback     settlements.created_at         Seq Scan on settlements
 *   earnings     ledger_journals.posted_at      Seq Scan on ledger_journals,
 *                                               feeding a hash join over a
 *                                               Seq Scan on ledger_entries
 *
 * Both endpoints pay it — the preview builds every sheet, not only the fifty
 * rows it returns — so a tab switch, a period change and a merchant-filter
 * change each cost a full scan. Harmless at today's volumes and exactly the
 * kind of thing that is no longer harmless by the time anybody notices.
 *
 * CONCURRENTLY, because this tree is served live: a plain CREATE INDEX takes
 * a lock that blocks writes to `transactions` for the duration, and a shop
 * crediting a sale would wait behind it. That forbids a surrounding
 * transaction, hence $withinTransaction = false and IF NOT EXISTS so a
 * re-run after an interrupted build is safe.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS transactions_occurred_at_index ON transactions (occurred_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS settlements_created_at_index ON settlements (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ledger_journals_posted_at_index ON ledger_journals (posted_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS transactions_occurred_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS settlements_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ledger_journals_posted_at_index');
    }
};
