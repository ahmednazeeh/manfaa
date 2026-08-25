<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GST readiness (owner, 2026-08-24). Manfaa is NOT GST-registered yet, so
 * this table ships with the switch OFF and every priced sale continues to
 * carry `fee_gst_laari = 0` exactly as it does today. Nothing about today's
 * pricing changes until a superadmin turns it on.
 *
 * ONE ROW, always — the TransferSetting precedent (a platform-wide policy
 * with strings on it, which PlatformConfig cannot hold: that store is
 * integers only, and a TIN is not an integer).
 *
 * The identity columns are not decoration. A GST-registered platform issues
 * tax invoices, and a tax invoice that cannot name the registrant is not a
 * tax invoice — so `gst_enabled` may only be set with a TIN, a business
 * name and an activity number on the row (enforced in the controller, which
 * answers 422). Enabling without them would mint non-compliant records at
 * till speed.
 *
 * fee_treatment decides what the stored fee MEANS the day GST is live:
 *
 *   on_top     the merchant owes cashback + fee + GST — the amount due goes
 *              UP by the tax, and Manfaa's fee income is unchanged.
 *   inclusive  the fee already contained the tax — the merchant owes the
 *              same total, and the GST share is carved OUT of Manfaa's own
 *              revenue (net fee income drops).
 *
 * Either way the columns on `transactions` mean one thing and only one
 * thing: `fee_laari` is Manfaa's NET fee revenue, `fee_gst_laari` is the
 * tax owed to MIRA, and the merchant owes their sum plus the cashback. That
 * invariant is what keeps every existing sum (the §8 accrual, the §7
 * settlement totals, the nightly Reconciler) correct under both treatments
 * without a single one of them learning the word "treatment".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_settings', function (Blueprint $table): void {
            $table->id();

            // OFF. Manfaa is not registered; the day it is, a superadmin
            // flips this and NEW sales start carrying tax. Existing rows are
            // never re-priced — they carry their own stamped rate.
            $table->boolean('gst_enabled')->default(false);

            // 8.00% — the Maldives GST general rate at the time of writing.
            // Basis points internally, like every other rate; the wire
            // carries a 2-decimal percent string (PLAN §1).
            $table->integer('gst_rate_bp')->default(800);

            // Who the platform is on a tax invoice. All three are required
            // before `gst_enabled` may become true.
            $table->string('gst_tin', 40)->nullable();
            $table->string('gst_business_name', 160)->nullable();
            $table->string('gst_activity_number', 40)->nullable();

            $table->string('fee_treatment', 12)->default('on_top');

            // Stamped on the TRANSITION to enabled and never rewritten by a
            // later rate edit: the instant the platform started charging
            // tax, which is the first thing an auditor asks for.
            $table->timestampTz('enabled_at')->nullable();

            $table->timestampsTz();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users');
        });

        DB::statement("ALTER TABLE tax_settings ADD CONSTRAINT tax_settings_fee_treatment_check CHECK (fee_treatment IN ('on_top', 'inclusive'))");
        // A rate outside 0-20% is not a rate this platform can price; the
        // ceiling is the same structural bound §4 puts on every other rate.
        DB::statement('ALTER TABLE tax_settings ADD CONSTRAINT tax_settings_rate_range_check CHECK (gst_rate_bp >= 0 AND gst_rate_bp <= 2000)');

        // Exactly one settings row, ever — seeded DISABLED.
        DB::table('tax_settings')->insert([
            'gst_enabled' => false,
            'gst_rate_bp' => 800,
            'fee_treatment' => 'on_top',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_settings');
    }
};
