<?php

declare(strict_types=1);

namespace App\Domain\Claims;

use App\Models\Claim;
use DomainException;

/**
 * The claimed amount sits below the merchant's minimum eligible amount, so
 * the equivalent live sale would have earned nothing (§9.2 below_minimum).
 * Approving it would mint a zero-cashback transaction; reject instead.
 */
final class ClaimBelowMinimumException extends DomainException
{
    public static function for(Claim $claim, int $minEligibleLaari): self
    {
        return new self(sprintf(
            'Claim #%d amount (%d laari) is below the merchant minimum of %d laari.',
            $claim->id,
            $claim->claimed_amount_laari,
            $minEligibleLaari,
        ));
    }
}
