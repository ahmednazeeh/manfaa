<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The words for `cashback_reversed` (owner decision 2026-08-22).
 *
 * The other end of `cashback_earned`: a customer who was told they earned
 * cashback is now told it was reversed, instead of watching the pending
 * amount vanish. `{{reason}}` is a short phrase or empty — "after a
 * refund", "because the sale was voided" — so the sentence stays honest
 * without a template per reason.
 *
 * Editable afterwards like every other template; this only puts the row
 * there, because a key with no row is a message nobody can send.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_templates')->updateOrInsert(
            ['key' => 'cashback_reversed'],
            [
                'body_en' => 'Your {{amount}} cashback from {{store}} was reversed{{reason}}. It no longer counts towards your next payout.',
                'body_dv' => '',
                'active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'cashback_reversed')->delete();
    }
};
