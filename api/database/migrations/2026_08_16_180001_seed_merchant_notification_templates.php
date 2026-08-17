<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The merchant-facing moments (M4).
     *
     * These reach the till APP, not a phone number: a store's staff have no
     * SMS relationship with the platform, and settlement is the one subject
     * a merchant genuinely wants interrupting them — the prompt-payment
     * discount is money with a clock on it.
     *
     * All three default to ACTIVE, unlike `cashback_earned`. A push costs
     * nothing per message, so the reason that one is off by default — a
     * per-sale SMS bill — does not apply, and a store that is not told its
     * transfer was refused simply does not act on it.
     *
     * @var array<string, array{en: string, dv: string}>
     */
    private const SEED = [
        'settlement_due' => [
            'en' => 'Settlement {{reference}}: transfer {{amount}} to Manfaa, then upload your receipt.',
            'dv' => 'ސެޓްލްމަންޓް {{reference}}: މަންފާއަށް {{amount}} ފޮނުވުމަށްފަހު ރަސީދު އަޕްލޯޑް ކުރައްވާ.',
        ],
        'settlement_accepted' => [
            'en' => 'Settlement {{reference}} is paid off. Thank you.',
            'dv' => 'ސެޓްލްމަންޓް {{reference}} ދައްކާ ނިމިއްޖެ. ޝުކުރިއްޔާ.',
        ],
        'settlement_rejected' => [
            'en' => 'Your receipt for settlement {{reference}} was not accepted: {{reason}}',
            'dv' => 'ސެޓްލްމަންޓް {{reference}} ގެ ރަސީދު ބަލައިނުގަނެވުނު: {{reason}}',
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SEED as $key => $copy) {
            if (! in_array($key, NotificationTemplateKey::values(), true)) {
                throw new RuntimeException("Seeded template [{$key}] matches no NotificationTemplateKey.");
            }

            // Idempotent: this migration must be safe to re-run against a
            // database where an admin has already edited the copy.
            DB::table('notification_templates')->updateOrInsert(
                ['key' => $key],
                [
                    'body_en' => $copy['en'],
                    'body_dv' => $copy['dv'],
                    'active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->whereIn('key', array_keys(self::SEED))->delete();
    }
};
