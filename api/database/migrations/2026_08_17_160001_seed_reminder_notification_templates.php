<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The deadline-reminder moments (MR4) and the §7 ladder's three rungs,
     * which now reach phones as well as the merchant_notices table.
     *
     * All five seed ACTIVE: they are push-only (no per-message SMS bill),
     * they exist because the owner asked for the reminders by name, and a
     * store that is not warned its discount dies today — or that suspension
     * follows tomorrow — simply loses money it was promised a warning about.
     *
     * English only — every notification body sends the English text
     * (decision 2026-08-17). Amounts and dates arrive server-computed; the
     * template only ever interpolates them (§10: no money derived in copy).
     */
    private const array SEED = [
        'prompt_discount_expiring' => 'Settle everything outstanding today and keep your {{rate}} prompt-payment discount — you save {{amount}}.',
        'settlement_due_soon' => '{{amount}} becomes overdue on {{date}}. Settle before then to stay in good standing.',
        'reminder_day10' => 'Reminder: your store owes {{amount}} in cashback and fees. Please settle by {{date}}.',
        'urgent_day13' => 'Urgent: {{amount}} is still outstanding and falls due on {{date}}. Settle now to keep cashback running.',
        'due_day15' => 'Payment due: {{amount}} must be settled today ({{date}}). Unsettled stores are suspended tomorrow.',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SEED as $key => $body) {
            if (! in_array($key, NotificationTemplateKey::values(), true)) {
                throw new RuntimeException("Seeded template [{$key}] matches no NotificationTemplateKey.");
            }

            // Insert-only: never clobber copy an admin may already have
            // written, should this migration ever re-run.
            if (DB::table('notification_templates')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('notification_templates')->insert([
                'key' => $key,
                'body_en' => $body,
                'body_dv' => null,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->whereIn('key', array_keys(self::SEED))->delete();
    }
};
