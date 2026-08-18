<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Marketplace enrolment outcomes (PLAN-marketplace.md §9).
     *
     * The merchant handed us identity documents and then waited on a human
     * decision they cannot chase — the same shape as store approval, and
     * the same answer: push AND SMS.
     *
     * English only — every notification body sends the English text
     * (decision 2026-08-17).
     */
    private const array SEED = [
        'marketplace_approved' => '{{store}} is approved to sell on the Manfaa marketplace. You can add products and start taking orders now.',
        'marketplace_rejected' => 'Your Manfaa marketplace application was not approved: {{reason}}',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SEED as $key => $body) {
            if (! in_array($key, NotificationTemplateKey::values(), true)) {
                throw new RuntimeException("Seeded template [{$key}] matches no NotificationTemplateKey.");
            }

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
