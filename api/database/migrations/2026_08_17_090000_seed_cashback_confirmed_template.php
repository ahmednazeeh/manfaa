<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The "your cashback is confirmed" moment (owner request, 2026-08-17):
     * fired when a sale's refund window closes — or an admin releases a hold
     * onto the clock — and the customer's Pending becomes Confirmed.
     *
     * Seeded ACTIVE, deliberately unlike `cashback_earned` originally was:
     * the owner asked for this notification by name, and the push half costs
     * nothing per message. English only — every notification sends the
     * English body (decision 2026-08-17).
     */
    public function up(): void
    {
        $key = NotificationTemplateKey::CashbackConfirmed->value;

        // Insert-only: never clobber copy an admin may already have written.
        if (DB::table('notification_templates')->where('key', $key)->exists()) {
            return;
        }

        $now = now();

        DB::table('notification_templates')->insert([
            'key' => $key,
            'body_en' => 'Your {{amount}} cashback at {{store}} is confirmed and will be included in your next payout. — Manfaa',
            'body_dv' => null,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->where('key', NotificationTemplateKey::CashbackConfirmed->value)
            ->delete();
    }
};
