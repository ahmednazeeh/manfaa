<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\Transaction;
use DomainException;

/**
 * The hold-review queue was asked to act on a transaction that is not (or is
 * no longer) on_hold — the row moved between the admin loading the queue and
 * clicking, or somebody aimed the endpoint at an arbitrary transaction id.
 *
 * Both queue actions raise it, and it is the guard that makes "never reject a
 * confirmed or paid row" true by construction rather than by remembering:
 * neither state can be on_hold (§6 — confirmed only goes to paid), so both
 * refuse here with 409 `not_on_hold` before any ledger or state code runs.
 */
final class NotOnHoldException extends DomainException
{
    public const string ERROR_CODE = 'not_on_hold';

    public function __construct(public readonly Transaction $transaction, string $message)
    {
        parent::__construct($message);
    }

    /**
     * The state is named with TransactionState::label(), not `->value`: the
     * panel renders this sentence verbatim in a toast, and `payable_unfunded`
     * in front of an operator is the exact leak task #22 exists to close.
     */
    public static function for(Transaction $transaction, string $action): self
    {
        return new self($transaction, sprintf(
            'Transaction %d is %s, not on hold, and cannot be %s from the hold queue.',
            $transaction->getKey(),
            $transaction->state->label(),
            $action,
        ));
    }
}
