<?php

declare(strict_types=1);

namespace App\Domain\Adjustment;

use App\Models\Transaction;
use DomainException;

/**
 * PLAN §1 "Backdated credits": a credit older than the merchant's validation
 * window is immediately payable AND merchant-irreversible. The merchant (or
 * their POS vendor) cannot take it back through any path — not in place, and
 * not as a credit adjustment either.
 *
 * The reason is that a backdated credit skipped the refund window entirely:
 * it was recorded as final, the customer was told so, and the 15-day clock
 * started at once. Letting the party who chose to backdate it also reverse it
 * would make "final" mean nothing — a merchant could credit a months-old sale
 * to satisfy a customer standing at the till and quietly undo it afterwards.
 * Corrections here are an ADMIN adjustment, reviewed by a human.
 *
 * Surfaces as 409 `backdated_irreversible` (docs/openapi.yaml), deliberately
 * distinct from `invalid_state` so a vendor can tell "you may never reverse
 * this" from "not in this state right now".
 */
final class BackdatedIrreversibleException extends DomainException
{
    public const string ERROR_CODE = 'backdated_irreversible';

    public function __construct(public readonly Transaction $transaction, string $message)
    {
        parent::__construct($message);
    }

    public static function for(Transaction $transaction): self
    {
        return new self($transaction, sprintf(
            'Transaction %d was credited as a backdated sale and cannot be reversed by the merchant — an admin adjustment is required.',
            $transaction->getKey(),
        ));
    }
}
