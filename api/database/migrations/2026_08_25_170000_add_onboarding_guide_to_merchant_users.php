<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The guided-setup tasklist (owner, 2026-08-25) is PER PERSON, not per
 * store: a shop owner signing up today and a cashier added in three months
 * each get their own five days, because the tasklist is read by a human who
 * has just arrived, not by a company. So the three timestamps live on
 * merchant_users.
 *
 *  - onboarding_started_at: the anchor the five days count from. Stamped
 *    the FIRST TIME the guide is asked for, never as a login side effect —
 *    a write bolted onto sign-in is a write that can fail a sign-in.
 *  - onboarding_skipped_at: the person dismissed it. Permanent and
 *    immediate; nothing un-skips.
 *  - onboarding_tour_completed_at: they finished the walkthrough, so the
 *    client stops offering it. Separate from skipping: finishing the tour
 *    does not retire the tasklist, whose items are still real work.
 *
 * Expiry is DERIVED from the anchor on every read (anchor + 5 days). No
 * cron, no expired flag: a column that has to be swept is a column that is
 * wrong between sweeps.
 *
 * BACKFILL: every account that already exists is anchored on its own
 * created_at, so a shop that has been trading for a month does not get a
 * "credit your first customer" tasklist on the deploy that ships this —
 * their five days elapsed long ago. An account created in the last five
 * days keeps the remainder, which is the honest reading of when their
 * first week started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_users', function (Blueprint $table): void {
            $table->timestampTz('onboarding_started_at')->nullable();
            $table->timestampTz('onboarding_skipped_at')->nullable();
            $table->timestampTz('onboarding_tour_completed_at')->nullable();
        });

        DB::statement('UPDATE merchant_users SET onboarding_started_at = created_at WHERE onboarding_started_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('merchant_users', function (Blueprint $table): void {
            $table->dropColumn([
                'onboarding_started_at',
                'onboarding_skipped_at',
                'onboarding_tour_completed_at',
            ]);
        });
    }
};
