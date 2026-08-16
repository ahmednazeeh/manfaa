<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutItem;
use DomainException;

/**
 * Paid and failed are terminal: recording a second outcome on an item would
 * post a second journal, or re-queue money that has already left the bank.
 * A mistyped reference on a paid item has no remedy here — it is a correction
 * for someone with the ledger open, not a button.
 */
final class InvalidPayoutItemStateException extends DomainException
{
    public static function for(PayoutItem $item): self
    {
        return new self(sprintf(
            'Payout item %s is already %s and cannot take another transfer result.',
            $item->idempotency_key,
            $item->state->value,
        ));
    }
}
