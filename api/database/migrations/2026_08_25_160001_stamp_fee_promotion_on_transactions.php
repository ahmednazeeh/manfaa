<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHY THIS SALE WAS CHEAPER, frozen at creation — the same law `rate_bp`,
 * `fee_bp` and the GST stamp already live by (§4/§5: a merchant holds a
 * receipt for what they were quoted).
 *
 * Ending a promotion, changing its fee, or moving its window prices NEW
 * sales only. Nothing ever reaches back: settlements, invoices and reports
 * read these stamped columns, never the live `fee_promotions` row.
 *
 * FOUR FACTS, and each one earns its column:
 *
 *   fee_promo_kind      WHICH promotion priced this sale — `introductory`
 *                       or `platform_wide`. NULL on every sale that paid
 *                       its ordinary tier fee, which is every row that
 *                       exists today.
 *   fee_promo_fee_bp    the fee that promotion OFFERED. Not the same as
 *                       `fee_bp`: the charged fee is min(offer, tier), so a
 *                       merchant already below the offer keeps their own
 *                       cheaper tier and this column still records what was
 *                       on the table. It is also what an AMENDMENT re-prices
 *                       a lined sale from, so a correction made after the
 *                       promotion ended reproduces the terms the sale was
 *                       rung up under.
 *   list_fee_bp         what the §4 TIER would have charged — the "before"
 *                       price. It is the MATCHED PAIR of `fee_bp` and
 *                       nothing else: `fee_bp` on the header is the
 *                       BASE-RATE SNAPSHOT (§4 — per-line truth lives on
 *                       the lines), so this is the before-price of that
 *                       same snapshot, and it is NULL whenever the
 *                       promotion did not beat the base rate's own tier
 *                       fee. On a LINED sale that therefore reads NULL even
 *                       when the promotion did reduce a CATEGORY line — the
 *                       header's `fee_promo_kind`, `fee_promo_fee_bp` and
 *                       `fee_forgone_laari` still say a promotion priced the
 *                       row and what it cost, and each line carries its own
 *                       `list_fee_bp` for the line's own before-price. The
 *                       pairing is what AmendmentService reads when a lined
 *                       sale is corrected back into an unlined one: two
 *                       numbers costed on the same rate, or neither.
 *                       FrozenFeePromotionTest pins this shape.
 *   fee_forgone_laari   the NET fee revenue Manfaa gave up on this row, in
 *                       integer laari. Recorded rather than derived because
 *                       the acquisition spend has to be summable in one
 *                       pass over the same scope the cashback report
 *                       already walks, and because deriving it later from
 *                       two basis points would have to re-do §4 ceiling
 *                       rounding — and a re-derivation that rounds even one
 *                       laari differently makes the dashboard disagree with
 *                       the reports, which is the one thing the money
 *                       surfaces may never do.
 *
 * NET, deliberately: `fee_laari` is Manfaa's net-of-GST revenue, so the
 * forgone figure is the difference between two NET fees and reads on the
 * same basis as every other fee number on the platform. With GST off (the
 * platform today) net and gross are the same number.
 *
 * LINES CARRY THEIR OWN `list_fee_bp` / `fee_forgone_laari` because fees are
 * per LINE on a lined credit and the header is the SUM of the stored line
 * integers (§4: round at the line, then sum). The KIND and the OFFER are
 * NOT duplicated onto lines — a line belongs to exactly one transaction,
 * both are written inside one database transaction, and a second copy could
 * only ever disagree with the first (the same argument that kept
 * `fee_treatment` off the lines).
 *
 * DEFAULTS ARE THE PAST. Every existing row gets NULL/NULL/NULL and
 * `fee_forgone_laari = 0`, which is exactly true: no fee promotion existed
 * when they priced, so none of them was charged a laari less than their
 * tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('fee_promo_kind', 16)->nullable()->after('fee_treatment');
            $table->integer('fee_promo_fee_bp')->nullable()->after('fee_promo_kind');
            $table->integer('list_fee_bp')->nullable()->after('fee_promo_fee_bp');
            $table->bigInteger('fee_forgone_laari')->default(0)->after('list_fee_bp');
        });

        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->integer('list_fee_bp')->nullable()->after('fee_bp');
            $table->bigInteger('fee_forgone_laari')->default(0)->after('fee_gst_laari');
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_fee_promo_kind_check CHECK (fee_promo_kind IS NULL OR fee_promo_kind IN ('introductory', 'platform_wide'))");
        // Money given up is never negative: the charged fee is min(offer,
        // tier), so the difference can only run one way.
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_fee_forgone_check CHECK (fee_forgone_laari >= 0)');
        DB::statement('ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_fee_forgone_check CHECK (fee_forgone_laari >= 0)');

        // "Show me every sale a promotion paid for" — a partial index, so it
        // costs nothing on the overwhelming majority of rows that carry no
        // promotion at all.
        DB::statement('CREATE INDEX transactions_fee_promo_kind_index ON transactions (fee_promo_kind, occurred_at) WHERE fee_promo_kind IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_fee_promo_kind_index');
        DB::statement('ALTER TABLE transaction_lines DROP CONSTRAINT IF EXISTS transaction_lines_fee_forgone_check');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_fee_forgone_check');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_fee_promo_kind_check');

        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->dropColumn(['list_fee_bp', 'fee_forgone_laari']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['fee_promo_kind', 'fee_promo_fee_bp', 'list_fee_bp', 'fee_forgone_laari']);
        });
    }
};
