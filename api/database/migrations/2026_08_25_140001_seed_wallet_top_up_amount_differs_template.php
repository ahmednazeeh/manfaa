<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The sentence for the moment a top-up is credited for something OTHER than
 * the figure the merchant typed (owner, 2026-08-25).
 *
 * `wallet_top_up_received` keeps its own wording for the ordinary case where
 * the two agree. This one exists because the honest sentence needs BOTH
 * numbers and the ordinary one needs neither — and a store reading "your
 * top-up of MVR 20.00 is in" when MVR 10.00 arrived would be told a lie by
 * their own platform.
 *
 * ACTIVE as shipped, like its siblings: this is the shop's own money, and a
 * discrepancy is precisely the thing they must be able to act on.
 */
return new class extends Migration
{
    private const KEY = 'wallet_top_up_amount_differs';

    private const EN = 'Your wallet top-up is in: {{amount}} arrived. That is not the {{claimed}} you entered — we credited what the bank actually sent. Wallet balance: {{balance}}.';

    private const DV = 'ތިޔަ ވޮލެޓަށް {{amount}} ޖަމާވެއްޖެ. ތިޔަ ޖެއްސެވި {{claimed}} އާ ތަފާތު — ޖަމާކުރީ ބޭންކުން ހަގީގަތުގައި ލިބުނު އަދަދު. ވޮލެޓް ބެލެންސް: {{balance}}.';

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
