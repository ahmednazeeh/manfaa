<?php

namespace App\Domain\Cashback;

enum TransactionState: string
{
    case Tracked = 'tracked';
    case AwaitingValidation = 'awaiting_validation';
    case PayableUnfunded = 'payable_unfunded';
    case OnHold = 'on_hold';
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case Reversed = 'reversed';
    case WrittenOff = 'written_off';

    /**
     * The state in words, for a sentence a person reads.
     *
     * PLAN §13b task #22 — no raw snake_case in rendered output. The panels
     * keep their own label maps (apps/*\/lib/labels.ts) because they own the
     * chips and the localisation, but a refusal MESSAGE is composed here and
     * rendered verbatim by whoever catches it: `abort(409, $e->getMessage())`
     * becomes the body of an admin toast. Interpolating `->value` into that
     * prose puts `payable_unfunded` in front of an operator, so the sentence
     * is built from these words instead. Same vocabulary as the admin
     * console's TRANSACTION_STATES, deliberately.
     */
    public function label(): string
    {
        return match ($this) {
            self::Tracked => 'tracked',
            self::AwaitingValidation => 'awaiting validation',
            self::PayableUnfunded => 'payable (unfunded)',
            self::OnHold => 'on hold',
            self::Confirmed => 'confirmed',
            self::Paid => 'paid',
            self::Reversed => 'reversed',
            self::WrittenOff => 'written off',
        };
    }
}
