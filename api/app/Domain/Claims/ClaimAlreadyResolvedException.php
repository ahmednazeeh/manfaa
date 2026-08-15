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
    /**
     * `state` is a plain string column, so it is narrowed onto the enum
     * before it is put into words; an unrecognised value degrades to
     * "resolved" rather than printing itself (PLAN §13b task #22).
     */
    public static function for(Claim $claim): self
    {
        $state = ClaimState::tryFrom((string) $claim->state);

        return new self(sprintf(
            'Claim #%d is already %s.',
            $claim->id,
            $state === null ? 'resolved' : $state->label(),
        ));
    }
}
