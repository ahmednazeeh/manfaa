<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects what the `include_reversed` column says about the exports taken
 * BEFORE the flag existed.
 *
 * The column arrived (2026_08_24_210000) with `default(false)`, which stamped
 * every audit row already in the table as an export that left reversed rows
 * out. The opposite is true: until this round every report simply included
 * them, because there was nothing to exclude them with. On live data the row
 * proves it against itself — `report_exports` id 1 is a cashback export of
 * 2026-08-01..25 with `row_count` 18, and rebuilding that exact window today
 * gives 15 rows with reversals out and 18 with them in.
 *
 * That is the one thing this table exists to answer — "which file did they
 * send the auditor" — being answered backwards, so it is worth a migration
 * rather than a comment. Three rows now; unreconstructable later.
 *
 * ONLY the cashback rows change. On payouts and earnings the flag is inert
 * (paid is terminal; the ledger always keeps reversal journals), so the
 * controller now records its EFFECTIVE value there — false — and the
 * pre-flag rows already say exactly that.
 *
 * The cutoff is the instant this migration runs, i.e. the deploy: every audit
 * row that predates it was written by code that had no flag. On a fresh
 * database (the test suite) the table is empty and this is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_exports')
            ->where('report', 'cashback')
            ->where('created_at', '<', CarbonImmutable::now('UTC'))
            ->update(['include_reversed' => true]);
    }

    public function down(): void
    {
        // Deliberately irreversible in the data sense: down() would have to
        // restore a value that was wrong, and there is no record of which
        // rows this touched. Dropping the column (the previous migration's
        // down) removes the claim entirely, which is the honest rollback.
    }
};
