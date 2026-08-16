<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use Carbon\CarbonImmutable;
use DomainException;

/**
 * The chosen cutoff has not arrived yet — a batch built now would silently
 * miss every confirmation still to come before it, so a build ahead of its
 * own cutoff is refused outright rather than producing a short batch.
 */
final class CutoffInFutureException extends DomainException
{
    public static function for(string $reference, CarbonImmutable $cutoff): self
    {
        return new self(sprintf(
            'Payout batch %s cannot be built before its cutoff (%s).',
            $reference,
            $cutoff->toIso8601String(),
        ));
    }
}
