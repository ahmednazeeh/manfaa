<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The wallet top-up moments (owner, 2026-08-24), plus the hourly
     * auto-settle line that phase 2 fires — seeded together so the catalogue
     * is edited once.
     *
     * All three default to ACTIVE: each is about the shop's own money
     * sitting with the platform, and a store not told its top-up was
     * refused simply does not act on it.
     *
     * @var array<string, array{en: string, dv: string}>
     */
    private const SEED = [
        'wallet_top_up_received' => [
            'en' => 'Your wallet top-up of {{amount}} is in. Wallet balance: {{balance}}.',
            'dv' => 'ތިޔަ ވޮލެޓަށް {{amount}} ޖަމާވެއްޖެ. ވޮލެޓް ބެލެންސް: {{balance}}.',
        ],
        'wallet_top_up_rejected' => [
            'en' => 'Your wallet top-up of {{amount}} was not accepted: {{reason}}',
            'dv' => 'ތިޔަ ވޮލެޓް ޓޮޕް-އަޕް {{amount}} ބަލައިނުގަނެވުނު: {{reason}}',
        ],
        'wallet_auto_settled' => [
            'en' => '{{amount}} was settled from your wallet for {{count}} sales. Wallet balance: {{balance}}.',
            'dv' => '{{count}} ވިޔަފާރިއަށް ތިޔަ ވޮލެޓުން {{amount}} ސެޓްލްކުރެވިއްޖެ. ވޮލެޓް ބެލެންސް: {{balance}}.',
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SEED as $key => $copy) {
            if (! in_array($key, NotificationTemplateKey::values(), true)) {
                throw new RuntimeException("Seeded template [{$key}] matches no NotificationTemplateKey.");
            }

            // Idempotent: safe to re-run where an admin has already edited
            // the copy — insert only, never overwrite.
            if (DB::table('notification_templates')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('notification_templates')->insert([
                'key' => $key,
                'body_en' => $copy['en'],
                'body_dv' => $copy['dv'],
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
