<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The deadline reminders (MR4) dedupe through merchant_notices exactly
     * as the §7 escalation ladder does — one evidenced row per send — so the
     * type CHECK learns their two names. Everything already allowed stays
     * allowed, byte for byte.
     */
    private const array TYPES = [
        // §7 ladder + standing sweeps, as created.
        'reminder_day10', 'urgent_day13', 'due_day15',
        'suspended', 'reinstated', 'write_off',
        // MR4 deadline reminders.
        'prompt_discount_expiring', 'settlement_due_soon',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE merchant_notices DROP CONSTRAINT IF EXISTS merchant_notices_type_check');
        DB::statement(sprintf(
            'ALTER TABLE merchant_notices ADD CONSTRAINT merchant_notices_type_check CHECK (type IN (%s))',
            implode(', ', array_map(fn (string $type): string => "'{$type}'", self::TYPES)),
        ));
    }

    public function down(): void
    {
        // The old, narrower constraint cannot hold rows of the new types.
        DB::table('merchant_notices')
            ->whereIn('type', ['prompt_discount_expiring', 'settlement_due_soon'])
            ->delete();

        DB::statement('ALTER TABLE merchant_notices DROP CONSTRAINT IF EXISTS merchant_notices_type_check');
        DB::statement("ALTER TABLE merchant_notices ADD CONSTRAINT merchant_notices_type_check CHECK (type IN ('reminder_day10', 'urgent_day13', 'due_day15', 'suspended', 'reinstated', 'write_off'))");
    }
};
