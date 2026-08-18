<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The MR9 review outcomes. A live store's public claims now wait for an
     * admin, so silence after a submission is the one thing these must never
     * be: the owner has to learn their new branch is live, or that their
     * rename was refused and why.
     *
     * Both seed ACTIVE. They are push-only to merchant staff (no per-message
     * SMS bill), and low-volume by construction — one per reviewed change.
     *
     * English only — every notification body sends the English text
     * (decision 2026-08-17).
     */
    private const array SEED = [
        'store_change_approved' => 'Your {{change}} was approved and is now live on Manfaa.',
        'store_change_rejected' => 'Your {{change}} was not approved: {{reason}}',
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
