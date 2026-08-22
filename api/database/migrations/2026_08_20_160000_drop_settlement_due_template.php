<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires `settlement_due` (owner, 2026-08-20).
 *
 * It fired only for a settlement resting in `awaiting_payment`, and the one
 * path that produced that state was the admin's `storeForMerchant` endpoint,
 * removed in the same change. Nothing in the api-client or the panel had
 * ever called it, and no settlement in production has ever been in that
 * state — only merchants create settlements, always with a receipt, which
 * lands them straight in payment_review.
 *
 * A moment nothing can produce is a message nobody can receive, and it sat
 * on the notifications settings page implying the opposite.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_templates')->where('key', 'settlement_due')->delete();
    }

    public function down(): void
    {
        DB::table('notification_templates')->updateOrInsert(
            ['key' => 'settlement_due'],
            [
                'body_en' => 'Settlement {{reference}}: transfer {{amount}} to settle your cashback balance.',
                'body_dv' => '',
                'active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};
