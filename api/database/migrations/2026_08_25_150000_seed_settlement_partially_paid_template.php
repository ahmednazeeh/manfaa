<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The sentence for a settlement transfer that was FOUND and banked, and did
 * not cover the batch (verifier round, 2026-08-25).
 *
 * `settlement_accepted` fires only on the move into settled, on purpose — a
 * store that still owes must not be told "paid off, thank you". Which left
 * the other half of the new world silent: once the BANK's figure funds the
 * allocation rather than the merchant's typed claim, a transfer that arrives
 * short is an ordinary auto-match, and the merchant surfaces promise a
 * message "the moment your transfer is matched". This is that message.
 *
 * ACTIVE as shipped, like its siblings: it is the store's own bill, and what
 * is left owing is precisely the thing they have to act on.
 */
return new class extends Migration
{
    private const KEY = 'settlement_partially_paid';

    private const EN = 'We matched your transfer for {{reference}}: {{amount}} arrived. That does not cover the batch — {{outstanding}} is still owed. Settle the rest to close it off.';

    private const DV = '{{reference}} އަށް ދެއްކެވި ފައިސާ ލިބިއްޖެ: {{amount}}. މިއަދަދުން ބެޗް ފުރިހަމައެއް ނުވޭ — އަދިވެސް {{outstanding}} ދައްކަންޖެހޭ. ބާކީ ދައްކަވައި ބެޗް ފުރިހަމަކުރައްވާ.';

    public function up(): void
    {
        if (! in_array(self::KEY, NotificationTemplateKey::values(), true)) {
            throw new RuntimeException('Seeded template ['.self::KEY.'] matches no NotificationTemplateKey.');
        }

        // Idempotent, and never overwrites copy an admin has already edited.
        if (DB::table('notification_templates')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('notification_templates')->insert([
            'key' => self::KEY,
            'body_en' => self::EN,
            'body_dv' => self::DV,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', self::KEY)->delete();
    }
};
