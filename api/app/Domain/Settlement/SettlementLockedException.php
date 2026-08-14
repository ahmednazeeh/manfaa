<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Models\Settlement;
use App\Models\Transaction;
use DomainException;

/**
 * Lines freeze the moment a settlement leaves draft (§6), and a transaction
 * sitting on a live (non-draft, non-cancelled) settlement is off-limits to
 * reversal paths (§7 locked batches).
 */
final class SettlementLockedException extends DomainException
{
    public static function linesFrozen(Settlement $settlement): self
    {
        return new self(sprintf(
            'Settlement %s is %s — lines are frozen once a settlement leaves draft.',
            $settlement->reference,
            $settlement->state->value,
        ));
    }

    public static function forTransaction(Transaction $transaction, Settlement $settlement): self
    {
        return new self(sprintf(
            'Transaction #%d sits on settlement %s (%s) and is locked — a reversal must become a credit adjustment on the next batch.',
            $transaction->getKey(),
            $settlement->reference,
            $settlement->state->value,
        ));
    }
}
