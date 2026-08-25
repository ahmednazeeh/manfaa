<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "GST now applies to your platform fee" (owner decision, 2026-08-24).
 *
 * Fired ONCE, by the superadmin action that switches GST on, to every
 * approved merchant. Never on a rate edit: a merchant needs to be told the
 * platform started charging tax, and does not need a push every time the
 * number is corrected — the number is on every settlement screen anyway.
 *
 * ACTIVE by default, like the other merchant-facing keys: a push costs
 * nothing per message, and a shop that is not told its bill has changed
 * will discover it at the bank instead.
 *
 * @var array<string, array{en: string, dv: string}>
 */
return new class extends Migration
{
    private const SEED = [
        'gst_now_applies' => [
            'en' => 'From {{date}}, GST of {{rate}} applies to Manfaa\'s platform fee — {{effect}}. '
                .'New sales show the fee and the GST separately. Sales already recorded are unchanged.',
            'dv' => '{{date}} ން ފެށިގެން މަންފާގެ ޕްލެޓްފޯމް ފީއަށް {{rate}} ޖީއެސްޓީ ނަގާނެ — {{effect}}. '
                .'އައު ވިޔަފާރިތަކުގައި ފީއާއި ޖީއެސްޓީ ވަކިން ދައްކާނެ. ކުރިން ރެކޯޑްކޮށްފައިވާ ވިޔަފާރިތަކަށް ބަދަލެއް ނާދޭ.',
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SEED as $key => $copy) {
            if (! in_array($key, NotificationTemplateKey::values(), true)) {
                throw new RuntimeException("Seeded template [{$key}] matches no NotificationTemplateKey.");
            }

            // Idempotent: safe to re-run against a database where an admin
            // has already edited the copy.
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
