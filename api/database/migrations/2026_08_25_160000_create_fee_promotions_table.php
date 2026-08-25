<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PLATFORM FEE PROMOTIONS (owner, 2026-08-25) — "I intend to use this
 * feature during initial merchant acquisition."
 *
 * ONE ROW, always, holding BOTH kinds of promotion. The TaxSetting /
 * TransferSetting precedent: PlatformConfig stores integers, and the banner
 * copy on this row is marketing prose in two scripts, which that store
 * cannot hold. It ships with both switches OFF, so nothing about today's
 * pricing changes until a superadmin turns one on.
 *
 * A) INTRODUCTORY (`intro_*`). Every merchant pays `intro_fee_bp` instead of
 *    their §4 tier fee for their first `intro_days` days on the platform.
 *    The clock is `merchants.approved_at` — the stamp that says the store
 *    could actually trade — measured in whole BUSINESS days (§13), so day 0
 *    is the calendar day the store was approved on in Malé.
 *
 *    THERE IS NO ENROLMENT TABLE, and that is the design. A merchant's
 *    window is a function of their own approval date and nothing else, so a
 *    store approved before this feature existed is not retro-enrolled: their
 *    first X days are their first X days, and if those have already passed
 *    they get nothing. Switching the promotion on cannot mint a backdated
 *    discount for a store that has been trading for a year.
 *
 * B) PLATFORM-WIDE (`wide_*`). A superadmin-set window during which EVERY
 *    merchant pays `wide_fee_bp`, whatever their age. Both edges are
 *    required when the switch is on (enforced in the controller): a
 *    promotion with no end is a price cut, and the banner has to be able to
 *    say when it stops.
 *
 * WHEN BOTH APPLY THE MERCHANT WINS — the LOWER fee prices the sale. See
 * App\Domain\Platform\FeePromotionPolicy, which is the only place that rule
 * lives.
 *
 * `*_fee_bp` is NULLABLE and 0 IS A LEGAL VALUE. Those are different facts:
 * NULL means "no promotional fee has been set", 0 means "this promotion
 * charges nothing at all", which is the whole point of the feature. A
 * switch turned on against a NULL fee is refused with a 422 rather than
 * quietly pricing at zero.
 *
 * BANNER COPY IS DATA, in en AND dv, because it is MARKETING: the sentence
 * a merchant reads on the panel, in the till app and on the public landing
 * changes with a campaign, and a campaign must not wait for a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_promotions', function (Blueprint $table): void {
            $table->id();

            // A) INTRODUCTORY — every merchant's first X days.
            $table->boolean('intro_enabled')->default(false);
            // X. Zero is the OFF state, and the controller refuses enabling
            // on a zero-day window: a promotion nobody is ever inside is a
            // banner that lies.
            $table->integer('intro_days')->default(0);
            $table->integer('intro_fee_bp')->nullable();
            $table->text('intro_banner_en')->nullable();
            $table->text('intro_banner_dv')->nullable();

            // B) PLATFORM-WIDE — a window that covers everybody.
            $table->boolean('wide_enabled')->default(false);
            $table->timestampTz('wide_from')->nullable();
            $table->timestampTz('wide_to')->nullable();
            $table->integer('wide_fee_bp')->nullable();
            $table->text('wide_banner_en')->nullable();
            $table->text('wide_banner_dv')->nullable();

            $table->timestampsTz();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users');
        });

        // The structural bounds. A fee of 0 is legal (the acquisition case);
        // the ceiling is the same 20.00% bound §4 puts on every other rate.
        DB::statement('ALTER TABLE fee_promotions ADD CONSTRAINT fee_promotions_intro_fee_range_check CHECK (intro_fee_bp IS NULL OR (intro_fee_bp >= 0 AND intro_fee_bp <= 2000))');
        DB::statement('ALTER TABLE fee_promotions ADD CONSTRAINT fee_promotions_wide_fee_range_check CHECK (wide_fee_bp IS NULL OR (wide_fee_bp >= 0 AND wide_fee_bp <= 2000))');
        // Ten years is not a promotion; it is the price list.
        DB::statement('ALTER TABLE fee_promotions ADD CONSTRAINT fee_promotions_intro_days_check CHECK (intro_days >= 0 AND intro_days <= 3650)');
        // A window whose end precedes its start describes no window at all.
        // The controller answers 422 for this; the constraint is the floor
        // under it, because a settings row is worth defending twice.
        DB::statement('ALTER TABLE fee_promotions ADD CONSTRAINT fee_promotions_window_order_check CHECK (wide_from IS NULL OR wide_to IS NULL OR wide_to > wide_from)');

        // Exactly one settings row, ever — seeded with both switches OFF.
        DB::table('fee_promotions')->insert([
            'intro_enabled' => false,
            'intro_days' => 0,
            'wide_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_promotions');
    }
};
