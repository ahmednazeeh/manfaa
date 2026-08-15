<?php

declare(strict_types=1);

namespace App\Domain\Claims;

enum ClaimState: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * The state in words (PLAN §13b task #22 — no raw snake_case in rendered
     * output). `in_review` is the one that would otherwise leak: the admin
     * claims screen echoes a 409 refusal message verbatim.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::InReview => 'in review',
            self::Approved => 'approved',
            self::Rejected => 'rejected',
        };
    }
}
