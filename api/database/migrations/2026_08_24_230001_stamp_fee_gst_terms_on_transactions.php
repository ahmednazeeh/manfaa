<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The GST terms a sale was priced under, FROZEN AT CREATION — the same law
 * `rate_bp` and `fee_bp` already live by (§4/§5: a merchant holds a receipt
 * for what they were quoted).
 *
 * Enabling GST, changing the rate, or switching the treatment prices NEW
 * transactions only. Nothing ever reaches back: reports, settlements and
 * the ledger read these stamped columns, never the live tax_settings row.
 * The reports round proved why — eight live settlements correctly report
 * the 5% prompt discount they were granted while new ones report 10%,
 * because the rate that priced them is stamped on the batch.
 *
 * DEFAULTS ARE THE PAST. Every existing row gets `fee_gst_bp = 0` and
 * `fee_treatment = 'on_top'`, which is precisely the arithmetic that
 * produced its stored `fee_gst_laari = 0`: at 0 bp both treatments are the
 * identity, so re-pricing any historical row from its own stamp reproduces
 * it byte for byte.
 *
 * Lines carry their own `fee_gst_bp` / `fee_gst_laari` because fees are per
 * LINE on a lined credit and the header total is the SUM of the stored line
 * integers (§4: round at the line, then sum). The TREATMENT is not
 * duplicated onto lines — a line belongs to exactly one transaction, both
 * are written inside one database transaction, and a second copy could only
 * ever disagree with the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // The GST rate this sale was priced at, in basis points. 0 means
            // no tax applied — which is every row on the platform today.
            $table->integer('fee_gst_bp')->default(0)->after('fee_gst_laari');
            // Which side of the fee the tax sat on when this sale priced.
            $table->string('fee_treatment', 12)->default('on_top')->after('fee_gst_bp');
        });

        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->integer('fee_gst_bp')->default(0)->after('fee_laari');
            $table->bigInteger('fee_gst_laari')->default(0)->after('fee_gst_bp');
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_fee_treatment_check CHECK (fee_treatment IN ('on_top', 'inclusive'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_fee_treatment_check');

        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->dropColumn(['fee_gst_bp', 'fee_gst_laari']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['fee_gst_bp', 'fee_treatment']);
        });
    }
};
