<?php

declare(strict_types=1);

namespace App\Domain\Adjustment;

use App\Models\Transaction;
use DomainException;

/**
 * The transaction sits in a terminal state no reversal (nor adjustment) can
 * touch: reversed or written_off. §9.2 maps this to 409 invalid_state.
 * (Paid is NOT terminal for reversals — a post-payout refund becomes a
 * credit adjustment with cause already_confirmed, per the published
 * contract.)
 */
final class InvalidReversalStateException extends DomainException
{
    public function __construct(public readonly Transaction $transaction, string $message)
    {
        parent::__construct($message);
    }

    public static function for(Transaction $transaction): self
    {
        return new self($transaction, sprintf(
            'Transaction %d is %s and cannot be reversed.',
            $transaction->getKey(),
            $transaction->state->label(),
        ));
    }
}
