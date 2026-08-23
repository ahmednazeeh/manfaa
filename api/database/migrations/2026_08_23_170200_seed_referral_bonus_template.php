<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The words for `referral_bonus_earned` (owner, 2026-08-23): the referrer's
 * friend crossed their spend milestone, and the bonus is already sitting in
 * the referrer's wallet.
 *
 * Active from the start, unlike cashback_earned: it fires at most once per
 * referred customer EVER, and it is push-only (the key spends no SMS), so
 * switching it on is not a bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_templates')->updateOrInsert(
            ['key' => 'referral_bonus_earned'],
            [
                'body_en' => 'Your friend {{friend}} hit their milestone — {{amount}} referral bonus is in your wallet.',
                'body_dv' => '',
                'active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'referral_bonus_earned')->delete();
    }
};
