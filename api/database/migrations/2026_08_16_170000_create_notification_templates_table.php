<?php

use App\Domain\Notifications\NotificationTemplateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seed copy. Deliberately plain: these go out as SMS, where every
     * character is billed and a customer reads them in a notification
     * shade, not on a page.
     *
     * @var array<string, array{en: string, dv: string, active: bool}>
     */
    private const SEED = [
        'cashback_earned' => [
            'en' => 'You earned {{amount}} cashback at {{store}}. — Manfaa',
            'dv' => '{{store}} އިން {{amount}} ކޭޝްބެކް ލިބިއްޖެ. — މަންފާ',
            // OFF by default. This fires on every sale, and switching on a
            // per-sale SMS bill is a decision with a number attached — it
            // should be taken deliberately, not inherited from a migration.
            'active' => false,
        ],
        'payout_paid' => [
            'en' => 'Your cashback payout of {{amount}} is on its way to your bank. Reference {{reference}}. — Manfaa',
            'dv' => 'ތިޔަބޭފުޅާގެ {{amount}} ގެ ކޭޝްބެކް ބޭންކަށް ފޮނުވިއްޖެ. ރެފަރެންސް {{reference}}. — މަންފާ',
            'active' => true,
        ],
    ];

    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            // The key is the join to the code catalogue
            // (NotificationTemplateKey), which is why it is unique and why
            // there is no free-text "create template" anywhere: a row whose
            // key nothing fires would be words no one ever reads.
            $table->string('key')->unique();
            $table->text('body_en');
            // Nullable so a store can be told in English only; the sender
            // falls back rather than sending an empty message.
            $table->text('body_dv')->nullable();
            $table->boolean('active')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();
        });

        $now = now();

        foreach (self::SEED as $key => $copy) {
            DB::table('notification_templates')->insert([
                'key' => $key,
                'body_en' => $copy['en'],
                'body_dv' => $copy['dv'],
                'active' => $copy['active'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Guards a typo in SEED above: a row whose key matches no case in
        // the code catalogue is a template nothing can ever fire.
        //
        // This deliberately checks SEED -> enum and no longer enum -> SEED.
        // The reverse direction is a property of the whole migration SET,
        // not of this one file, and asserting it here made every later
        // moment (M4 added three merchant ones) fail this migration on a
        // fresh database. It is covered continuously instead by
        // CustomerNotificationTest, which asserts the seeded rows and the
        // enum agree exactly — a better home, because it runs on every
        // commit rather than only at migrate time.
        foreach (array_keys(self::SEED) as $key) {
            if (! in_array($key, NotificationTemplateKey::values(), true)) {
                throw new RuntimeException("Seeded template [{$key}] matches no NotificationTemplateKey.");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
