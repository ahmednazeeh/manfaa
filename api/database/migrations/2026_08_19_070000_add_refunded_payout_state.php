<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A payout whose money went BACK to the wallet is finished, not retryable
 * (security audit, 2026-08-19).
 *
 * `failed` did two incompatible jobs. A refusal that proved no debit
 * returned the amount to the customer's wallet — correctly — and left the
 * row `failed`, which is in CustomerPayout::SENDABLE. An admin doing the
 * obvious thing and retrying the failed transfer would then send it: the
 * customer receives the bank transfer AND still holds the returned balance,
 * which they can withdraw again. The same laari leaves twice.
 *
 * `refunded` is the terminal state for that case. The money is back where it
 * came from, and getting it out again means asking for a fresh withdrawal —
 * which debits the wallet properly, exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customer_payouts DROP CONSTRAINT IF EXISTS customer_payout_state_check');
        DB::statement("
            ALTER TABLE customer_payouts ADD CONSTRAINT customer_payout_state_check
            CHECK (state IN ('pending', 'processing', 'pending_approval', 'sent', 'failed', 'refunded', 'cancelled'))
        ");
    }

    public function down(): void
    {
        // Anything already refunded would violate the narrower rule, so it
        // becomes `failed` again — the state it would have had before.
        DB::table('customer_payouts')->where('state', 'refunded')->update(['state' => 'failed']);

        DB::statement('ALTER TABLE customer_payouts DROP CONSTRAINT IF EXISTS customer_payout_state_check');
        DB::statement("
            ALTER TABLE customer_payouts ADD CONSTRAINT customer_payout_state_check
            CHECK (state IN ('pending', 'processing', 'pending_approval', 'sent', 'failed', 'cancelled'))
        ");
    }
};
