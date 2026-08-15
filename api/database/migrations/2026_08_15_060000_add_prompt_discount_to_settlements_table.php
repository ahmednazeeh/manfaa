<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prompt-payment discount (PLAN §1): 5% off the PLATFORM FEE — never the
     * customer's cashback — when a merchant settles EVERYTHING outstanding
     * and every line is still young. Additive: existing batches read 0 / null
     * and price exactly as they always did.
     *
     * - discount_laari   the relief actually granted, in laari, already
     *                    subtracted from amount_due_laari. It is the fee
     *                    discount plus (when fee GST is ever switched on) the
     *                    GST recomputed proportionally on the discounted fee;
     *                    with GST at zero everywhere today the two are the
     *                    same number. This is the figure that participates in
     *                    allocation as covered funds.
     * - discount_rate_bp the rate the grant was computed at, so a batch can
     *                    always be re-derived from its own row after the
     *                    platform setting moves. NULL when nothing was
     *                    granted — the batch was priced at no rate at all.
     * - discount_reason  the machine reason key, granted or refused
     *                    (eligible / not_all_outstanding / line_too_old /
     *                    clock_not_started / disabled), so the merchant can
     *                    be told WHY they did not get it and support can
     *                    answer the question a year later.
     * - discount_posted_laari
     *                    how much of it has reached the LEDGER. The discount
     *                    posts at allocation, not at submit — a rejected
     *                    receipt must leave no discount journal behind — and
     *                    a batch can allocate across several matches, so the
     *                    posted total is a fact the row carries rather than
     *                    something three different readers each re-derive
     *                    from the allocation walk. It is what makes the
     *                    posting idempotent (a re-match adds nothing) and
     *                    what lets the daily reconciliation subtract exactly
     *                    the revenue the ledger actually gave up. Written in
     *                    the same transaction as the journal it counts.
     */
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->bigInteger('discount_laari')->default(0)->after('fee_gst_total_laari');
            $table->bigInteger('discount_posted_laari')->default(0)->after('discount_laari');
            $table->integer('discount_rate_bp')->nullable()->after('discount_posted_laari');
            $table->string('discount_reason')->nullable()->after('discount_rate_bp');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn(['discount_laari', 'discount_posted_laari', 'discount_rate_bp', 'discount_reason']);
        });
    }
};
