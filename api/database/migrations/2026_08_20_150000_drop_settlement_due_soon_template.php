<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires `settlement_due_soon` (owner, 2026-08-20).
 *
 * It fired on the two mornings before the due date — days 13 and 14 — which
 * are exactly the days EscalationLadder already sends `urgent_day13` on.
 * Merchants were getting two pushes AND two texts each of those mornings,
 * from two schedulers running at the same hour, saying much the same thing.
 *
 * The ladder is the single escalation authority now: day 9 discount
 * deadline, 10–12 reminder, 13–14 urgent, 15+ due. The reminder sweep keeps
 * only the discount deadline, which the ladder has no equivalent for.
 *
 * Historical `merchant_notices` rows of this type are LEFT ALONE — they are
 * evidence of messages that really were sent, and deleting them would make
 * the ladder's own dedupe history lie about the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_templates')->where('key', 'settlement_due_soon')->delete();
    }

    public function down(): void
    {
        DB::table('notification_templates')->updateOrInsert(
            ['key' => 'settlement_due_soon'],
            [
                'body_en' => '{{amount}} becomes overdue on {{date}}. Settle before then to stay in good standing.',
                'body_dv' => '',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
};
