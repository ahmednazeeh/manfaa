<?php

declare(strict_types=1);

namespace App\Domain\Claims;

use App\Models\Claim;
use DomainException;

/**
 * Approve/reject raced or repeated: the claim already left the queue.
 */
final class ClaimAlreadyResolvedException extends DomainException
{
    public static function for(Claim $claim): self
    {
        return new self(sprintf('Claim #%d is already %s.', $claim->id, $claim->state));
    }
}
