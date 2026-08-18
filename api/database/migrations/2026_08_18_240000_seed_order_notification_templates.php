<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Marketplace order moments (PLAN-marketplace.md §8).
     *
     * Each is something the customer cannot see for themselves — nobody is
     * holding a screen when a shop accepts, cuts or hands over their
     * shopping. `order_placed` is the one that goes the other way, to the
     * shop.
     *
     * `order_rejected` and `order_amended` also text, because both cost the
     * customer money and are the two they must not miss.
     *
     * English only — every notification body sends the English text
     * (decision 2026-08-17).
     */
    private const array SEED = [
        'order_placed' => 'New Manfaa order {{reference}} for {{amount}}. Open the app to accept it.',
        'order_accepted' => '{{store}} accepted order {{reference}} and is preparing it now.',
        'order_rejected' => '{{store}} could not fulfil order {{reference}}: {{reason}} You will be refunded in full.',
        'order_amended' => '{{store}} changed order {{reference}}. {{amount}} is being refunded to you.',
        'order_ready' => 'Order {{reference}} is ready to collect at {{store}}.',
        'order_out_for_delivery' => 'Order {{reference}} from {{store}} is on its way.',
        'order_delivered' => 'Order {{reference}} from {{store}} has been delivered. Your cashback follows once the store validates it.',
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
