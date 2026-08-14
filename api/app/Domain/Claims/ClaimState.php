<?php

declare(strict_types=1);

namespace App\Domain\Claims;

enum ClaimState: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
