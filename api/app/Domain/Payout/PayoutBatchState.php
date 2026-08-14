<?php

namespace App\Domain\Payout;

enum PayoutBatchState: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Processing = 'processing';
    case Sent = 'sent';
    case Completed = 'completed';
    case PartiallyFailed = 'partially_failed';
}
