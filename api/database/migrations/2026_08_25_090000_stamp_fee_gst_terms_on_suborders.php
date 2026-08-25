<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The marketplace order fee is the platform's OTHER charge on a merchant —
 * the sibling of the till fee — and it is deducted from what the shop is
 * paid (`payable_to_merchant_laari`). When GST is switched on it must be
 * taxed exactly as the till fee is, or Manfaa would charge tax on one of
 * its two fee streams and not the other.
 *
 * `order_fee_gst_laari` already existed and was hardcoded to zero. These
 * two columns are what make it real: the terms the suborder was priced
 * under, FROZEN AT PLACEMENT, exactly as `cashback_rate_bp` and
 * `order_fee_bp` beside them already are.
 *
 * The stamp is what makes an AMENDMENT safe. A fulfilment that drops items
 * re-prices the suborder from its own frozen integers (§5.4c); reading the
 * live setting there would re-price a month-old order under today's tax.
 *
 * DEFAULTS ARE THE PAST: `0` / `on_top` is precisely the arithmetic that
 * produced every stored `order_fee_gst_laari = 0`, because at 0 bp both
 * treatments are the identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suborders', function (Blueprint $table): void {
            $table->integer('order_fee_gst_bp')->default(0)->after('order_fee_gst_laari');
            $table->string('order_fee_treatment', 12)->default('on_top')->after('order_fee_gst_bp');
        });

        DB::statement("ALTER TABLE suborders ADD CONSTRAINT suborders_order_fee_treatment_check CHECK (order_fee_treatment IN ('on_top', 'inclusive'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE suborders DROP CONSTRAINT IF EXISTS suborders_order_fee_treatment_check');

        Schema::table('suborders', function (Blueprint $table): void {
            $table->dropColumn(['order_fee_gst_bp', 'order_fee_treatment']);
        });
    }
};
