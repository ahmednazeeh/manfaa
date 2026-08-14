<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Models\Merchant;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * A promotion boosts the standing rate, so without a standing rate row
 * effective at starts_at there is nothing to boost against.
 */
final class NoStandingRateException extends DomainException
{
    public static function for(Merchant $merchant, CarbonImmutable $at): self
    {
        return new self(sprintf(
            'Merchant #%d has no standing cashback rate effective at %s — a promotion needs a standing rate to boost.',
            $merchant->getKey(),
            $at->toIso8601String(),
        ));
    }
}
