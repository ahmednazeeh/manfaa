<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use DomainException;

/**
 * A live (non-cancelled) batch already carries this period's reference —
 * rebuilding a draft is allowed by cancel + recreate only.
 */
final class DuplicatePayoutBatchException extends DomainException
{
    public static function for(string $reference): self
    {
        return new self(sprintf(
            'Payout batch %s already exists — cancel the draft first to rebuild it.',
            $reference,
        ));
    }
}
