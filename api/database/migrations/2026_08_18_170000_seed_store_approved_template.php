<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The store passed review (owner decision 2026-08-18).
     *
     * The only merchant-facing moment that spends an SMS as well as a push:
     * the merchant has been waiting on a decision they cannot chase, and it
     * may reach them before they have ever opened the app. Once per store,
     * so the bill is bounded by construction.
     *
     * English only — every notification body sends the English text
     * (decision 2026-08-17).
     */
    private const array SEED = [
        'store_approved' => '{{store}} is approved and live on Manfaa. Customers can find you and earn cashback from now on.',
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
