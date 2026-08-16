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

        // A key in the code with no row would be a template the admin screen
        // cannot show and the sender cannot render — fail the migration
        // rather than discover it at the first send.
        $seeded = array_keys(self::SEED);

        foreach (NotificationTemplateKey::values() as $key) {
            if (! in_array($key, $seeded, true)) {
                throw new RuntimeException("NotificationTemplateKey [{$key}] has no seeded template row.");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
