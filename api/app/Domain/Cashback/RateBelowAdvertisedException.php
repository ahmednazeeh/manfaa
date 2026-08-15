<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\Percent;
use RuntimeException;

/**
 * A per-sale `cashback_rate_percent` override BELOW the rate the sale would
 * otherwise earn was refused (PLAN §1 "Per-sale rate override").
 *
 * The advertised rate — the standing rate, or a live promotion covering
 * this sale — is a public promise to the customer. An override may only
 * BOOST it (mirroring the promotion floor: a promotion never pays less than
 * no promotion). Cutting the rate for one sale would let the till quietly
 * pay a customer less than the storefront advertises, which is exactly the
 * failure the frozen-rate law exists to prevent.
 *
 * Distinct from `rate_not_priced` on purpose: that one says the platform
 * cannot price the fee for the rate at all, this one says the rate is
 * priceable but dishonest.
 */
final class RateBelowAdvertisedException extends RuntimeException
{
    public const string CODE = 'rate_below_advertised';

    private function __construct(
        string $message,
        public readonly int $overrideBp,
        public readonly int $advertisedBp,
    ) {
        parent::__construct($message);
    }

    public static function for(int $overrideBp, int $advertisedBp): self
    {
        return new self(
            sprintf(
                'cashback_rate_percent %s%% is below the %s%% this sale already earns — an override may only raise the advertised rate.',
                Percent::format($overrideBp),
                Percent::format($advertisedBp),
            ),
            $overrideBp,
            $advertisedBp,
        );
    }
}
