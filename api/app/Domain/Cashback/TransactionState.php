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
}
