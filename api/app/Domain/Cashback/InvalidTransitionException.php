<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\Transaction;
use DomainException;

final class InvalidTransitionException extends DomainException
{
    public static function between(Transaction $transaction, TransactionState $from, TransactionState $to): self
    {
        return new self(sprintf(
            'Transaction #%d cannot move from %s to %s.',
            $transaction->getKey(),
            $from->value,
            $to->value,
        ));
    }

    public static function reverseAfterConfirmation(Transaction $transaction): self
    {
        return new self(sprintf(
            'Transaction #%d is %s and can no longer be reversed — post-confirmation corrections are adjustments, never reversals.',
            $transaction->getKey(),
            $transaction->state->value,
        ));
    }
}
