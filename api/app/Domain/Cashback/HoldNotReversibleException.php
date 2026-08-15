<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Adjustment\ReversalOutcome;
use App\Models\Transaction;
use DomainException;

/**
 * A hold-queue Reject could not reverse the sale in place, so it was refused
 * and rolled back whole.
 *
 * The §9.2 tree (ReversalService) answers a reversal request in one of two
 * ways: it reverses the transaction and mirrors the accrual, or — when the
 * line is frozen inside a non-draft settlement, or the reward has already
 * confirmed — it leaves the transaction alone and raises a credit adjustment
 * that nets the merchant's next batch instead.
 *
 * The second answer is right for a POS reversal and wrong for this queue. The
 * Reject dialog promises the admin one thing ("the accrual reverses, the
 * customer's cashback is cancelled"); an adjustment memo delivers something
 * else entirely — the cashback stands, the transaction stays on_hold, and the
 * row returns to the queue the admin thought they had just cleared. So the
 * outcome is checked, and anything other than an in-place reversal aborts the
 * enclosing DB transaction: no memo is left behind, and the admin is told to
 * deal with the settlement first.
 *
 * `cause` is ReversalOutcome's own vocabulary (`locked_in_settlement`,
 * `already_confirmed`) and is surfaced as the 409 error code, so the panel
 * can explain the specific obstruction.
 */
final class HoldNotReversibleException extends DomainException
{
    public const string ERROR_CODE = 'hold_not_reversible';

    public function __construct(
        public readonly Transaction $transaction,
        public readonly ?string $cause,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function lockedInSettlement(Transaction $transaction): self
    {
        return new self($transaction, 'locked_in_settlement', sprintf(
            'Transaction %d is on a settlement that has left draft — reject or cancel that settlement first, then reject the hold.',
            $transaction->getKey(),
        ));
    }

    public static function because(Transaction $transaction, ?string $cause): self
    {
        if ($cause === 'locked_in_settlement') {
            return self::lockedInSettlement($transaction);
        }

        return new self($transaction, $cause, sprintf(
            'Transaction %d cannot be reversed in place — %s, so its cashback would stand and the hold would remain.',
            $transaction->getKey(),
            self::causeInWords($cause),
        ));
    }

    /**
     * ReversalOutcome's cause in words. The code still travels as the 409
     * `code` so the panel can branch on it; this is the half a person reads,
     * and it never prints the code (PLAN §13b task #22).
     */
    private static function causeInWords(?string $cause): string
    {
        return match ($cause) {
            ReversalOutcome::CAUSE_ALREADY_CONFIRMED => 'its reward has already confirmed',
            ReversalOutcome::CAUSE_LOCKED_IN_SETTLEMENT => 'it is frozen inside a submitted settlement',
            default => 'the reversal became a credit adjustment instead',
        };
    }

    public function errorCode(): string
    {
        return $this->cause ?? self::ERROR_CODE;
    }
}
