<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The words for `customers_paid` (owner request 2026-08-20).
 *
 * A merchant settles cashback to the platform weeks before the customers who
 * earned it are paid, and until now heard nothing when that finally
 * happened — the one party who funded the money never learned it landed.
 *
 * Editable afterwards like every other template; this only puts the row
 * there, because a key with no row is a message nobody can send.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_templates')->updateOrInsert(
            ['key' => 'customers_paid'],
            [
                'body_en' => 'Manfaa has paid {{customers}} of your customers the {{amount}} cashback they earned at your store.',
                'body_dv' => '',
                'active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'customers_paid')->delete();
    }
};
