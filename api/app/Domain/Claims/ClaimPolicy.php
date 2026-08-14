<?php

declare(strict_types=1);

namespace App\Domain\Claims;

/**
 * Submission rules for missing-transaction claims (§10 apps/web).
 */
final class ClaimPolicy
{
    /**
     * How far back a claimed purchase may lie. Day 90 is accepted, day 91 is
     * not — older sales are past the point where the merchant's records can
     * reasonably be checked.
     */
    public const int WINDOW_DAYS = 90;
}
