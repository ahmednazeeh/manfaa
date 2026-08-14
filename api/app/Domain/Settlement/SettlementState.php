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
}
