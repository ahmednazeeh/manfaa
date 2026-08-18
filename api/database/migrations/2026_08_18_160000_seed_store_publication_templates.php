<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A store pausing itself, and coming back (owner decision 2026-08-18).
     *
     * Written for someone who knows the shop, because that is exactly who
     * receives it: only customers who have already earned cashback there.
     * The paused one says what changed and stops — no apology on the
     * merchant's behalf, and no promise about when they will be back, which
     * is not ours to make.
     *
     * Push only (NotificationTemplateKey::usesSms) and capped at one of each
     * per store per day, so the switch cannot become a broadcast.
     *
     * English only — every notification body sends the English text
     * (decision 2026-08-17).
     */
    private const array SEED = [
        'store_paused' => '{{store}} has paused cashback on Manfaa for now. Your earlier cashback from them is unaffected.',
        'store_resumed' => '{{store}} is offering cashback on Manfaa again.',
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
