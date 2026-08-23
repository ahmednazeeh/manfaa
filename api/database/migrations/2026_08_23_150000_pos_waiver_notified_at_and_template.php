<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The `pos_waiver_earned` moment (owner, 2026-08-23): the words, and a
 * once-only stamp on the evaluation row so a re-run can never send the
 * same good news twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_waiver_evaluations', function (Blueprint $table): void {
            $table->timestampTz('notified_at')->nullable()->after('evaluated_at');
        });

        DB::table('notification_templates')->updateOrInsert(
            ['key' => 'pos_waiver_earned'],
            [
                'body_en' => 'Your IsleBooks invoice for {{month}} is waived — {{amount}} {{track}} through Manfaa. Thank you for growing with us.',
                'body_dv' => '',
                'active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('key', 'pos_waiver_earned')->delete();

        Schema::table('pos_waiver_evaluations', function (Blueprint $table): void {
            $table->dropColumn('notified_at');
        });
    }
};
