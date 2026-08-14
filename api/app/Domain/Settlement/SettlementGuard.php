<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Settlement;
use App\Models\Transaction;

/**
 * §7 locked batches: a transaction whose line sits on a non-draft,
 * non-cancelled settlement cannot be reversed — the vendor's reversal must
 * become a credit adjustment on the next batch, signalled with a distinct
 * error code.
 *
 * Deliberately NOT wired into TransitionService: Phase 1 has no reversal
 * entry point that could reach a locked line (allocation only ever moves
 * lines forward to confirmed). NOTE: the Phase 2 API reversal path
 * (ReversalService) does not call this static helper — it re-implements the
 * same §7 check INSIDE its DB transaction with the settlement row locked,
 * so a reversal racing a draft submit() serialises instead of reversing a
 * freshly frozen line. Any new reversal entry point must do the same;
 * checking outside the transaction is a race.
 */
final class SettlementGuard
{
    public static function assertNotLockedInSettlement(Transaction $transaction): void
    {
        /** @var Settlement|null $settlement */
        $settlement = Settlement::query()
            ->join('settlement_lines', 'settlement_lines.settlement_id', '=', 'settlements.id')
            ->where('settlement_lines.transaction_id', $transaction->id)
            ->whereNotIn('settlements.state', [SettlementState::Draft->value, SettlementState::Cancelled->value])
            ->select('settlements.*')
            ->first();

        if ($settlement !== null) {
            throw SettlementLockedException::forTransaction($transaction, $settlement);
        }
    }
}
