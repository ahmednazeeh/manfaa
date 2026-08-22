<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Money;

use Manfaa\Cashback\Api\RateCard;

/**
 * "Estimated cashback" for the cart, computed exactly as the server will:
 * amounts summed PER MANFAA CATEGORY BUCKET first, then ceiling-rounded per
 * bucket — never per cart item, because a sum of per-item ceilings
 * over-estimates. Standing and category rates only; promotions are not
 * added (their per-customer cap is unknowable here, and under-promising is
 * the safe error).
 */
final class Estimator
{
    /**
     * @param  array<string|int, int>  $buckets  bucket key (`''` for the default bucket, else the Manfaa slug) => laari
     * @return array{eligible_laari:int, estimate_laari:int, shortfall_laari:int}
     */
    public static function estimate(RateCard $card, array $buckets): array
    {
        $eligible = 0;
        $estimate = 0;

        foreach ($buckets as $key => $laari) {
            $laari = (int) $laari;

            if ($laari <= 0) {
                continue;
            }

            $eligible += $laari;
            $slug = $key === '' || $key === 0 ? null : (string) $key;
            $estimate += Laari::cashback($laari, $card->bucketRateBp($slug));
        }

        if ($eligible < $card->minEligibleLaari) {
            return [
                'eligible_laari' => $eligible,
                'estimate_laari' => 0,
                'shortfall_laari' => $card->minEligibleLaari - $eligible,
            ];
        }

        return ['eligible_laari' => $eligible, 'estimate_laari' => $estimate, 'shortfall_laari' => 0];
    }
}
