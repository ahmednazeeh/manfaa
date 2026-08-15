<?php

namespace App\Domain\Settlement;

enum SettlementState: string
{
    case Draft = 'draft';
    case AwaitingPayment = 'awaiting_payment';
    case PaymentReview = 'payment_review';
    case Settled = 'settled';
    case PartiallySettled = 'partially_settled';
    case Cancelled = 'cancelled';

    /**
     * The state in words, for a sentence a person reads (PLAN §13b task #22 —
     * no raw snake_case in rendered output). Refusal messages built from
     * these are echoed verbatim by the merchant and admin panels, so
     * `awaiting_payment` must never be what a shopkeeper is shown.
     *
     * Plain phrases, no article: callers compose them into their own
     * sentences ("... is awaiting payment", "only a draft batch can").
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::AwaitingPayment => 'awaiting payment',
            self::PaymentReview => 'in payment review',
            self::Settled => 'settled',
            self::PartiallySettled => 'partially settled',
            self::Cancelled => 'cancelled',
        };
    }
}
