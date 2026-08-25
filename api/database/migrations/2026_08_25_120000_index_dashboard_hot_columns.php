<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The three columns the ADMIN LANDING PAGE drives on (owner, 2026-08-25).
 *
 * 2026_08_24_170000 indexed what the on-demand reports periodise on. The
 * dashboard then made three of those scopes a page that every admin opens at
 * login and leaves open on a 60-second poll, and it reaches two paths that
 * round missed:
 *
 *   transaction_events (to_state, created_at)
 *       PayoutReport::paidScope() groups `to_state = 'paid'` by transaction
 *       to find each sale's PAID event. Nothing on the table leads with
 *       to_state or created_at — only (transaction_id) and
 *       (transaction_id, to_state) — so every plan was a Seq Scan of the
 *       whole event log. The superadmin dashboard runs that scope THREE
 *       times per request (the period's payout total, the previous period's,
 *       and the daily chart). Measured on a 20,000-transaction /
 *       80,000-event fixture asking for one month: Seq Scan over 80,000 rows
 *       building 20,000 hash groups to keep 1,550, 7.45ms. With this index
 *       and the pushed lower bound in paidScope(): Bitmap Index Scan over
 *       1,750 rows, 1.68ms — and O(period) rather than O(lifetime), so the
 *       gap widens with every month the platform runs.
 *
 *   customers (created_at), merchants (created_at)
 *       GrowthCounts' three period counts. This is the PLAIN-ADMIN path —
 *       the payload every admin polls, whether or not they may see money —
 *       and customers is the fastest-growing table in the schema. At 200,000
 *       customers the period count was a Parallel Seq Scan at 10.6ms; on the
 *       index it is a Bitmap Index Scan at 1.64ms.
 *
 * The unqualified lifetime `COUNT(*)` in GrowthCounts is deliberately left
 * alone: no index removes a full count, and it is the one figure on the page
 * that could tolerate a short cache if it ever matters.
 *
 * CONCURRENTLY, because this tree is served live: a plain CREATE INDEX takes
 * a lock that blocks writes for the duration, and a shop crediting a sale
 * would wait behind it. That forbids a surrounding transaction, hence
 * $withinTransaction = false and IF NOT EXISTS so a re-run after an
 * interrupted build is safe.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS transaction_events_to_state_created_at_index ON transaction_events (to_state, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS customers_created_at_index ON customers (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS merchants_created_at_index ON merchants (created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS transaction_events_to_state_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_created_at_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS merchants_created_at_index');
    }
};
