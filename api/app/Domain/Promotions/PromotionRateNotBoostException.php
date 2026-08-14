<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use DomainException;

/**
 * A promotion is a BOOST on top of the merchant's standing rate — never a
 * stealth decrease. The promo rate must strictly EXCEED the standing rate
 * effective at starts_at; a merchant who wants to pay less goes through the
 * §7 rate-change path instead, where decreases take effect only at the next
 * business-day midnight so a stale till can never over-promise.
 */
final class PromotionRateNotBoostException extends DomainException
{
    public static function against(int $promoRateBp, int $standingRateBp): self
    {
        return new self(sprintf(
            'Promotion rate %d bp does not exceed the standing rate %d bp effective at the promotion start. '
            .'A promotion must be a boost; rate decreases go through the rate-change path.',
            $promoRateBp,
            $standingRateBp,
        ));
    }
}
