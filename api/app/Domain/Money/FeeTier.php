<?php

declare(strict_types=1);

namespace App\Domain\Money;

use OutOfRangeException;

/**
 * Resolves the platform fee from the customer cashback rate (§4 tier table).
 */
final class FeeTier
{
    public static function feeBpFor(int $cashbackBp): int
    {
        return match (true) {
            $cashbackBp >= 50 && $cashbackBp <= 99 => 25,
            $cashbackBp >= 100 && $cashbackBp <= 199 => 50,
            $cashbackBp >= 200 && $cashbackBp <= 499 => 75,
            $cashbackBp >= 500 && $cashbackBp <= 1000 => 100,
            default => throw new OutOfRangeException(
                sprintf('No fee tier for %d basis points; cashback rates are 50-1000.', $cashbackBp)
            ),
        };
    }

    public static function feeFor(Rate $cashbackRate): Rate
    {
        return Rate::fee(self::feeBpFor($cashbackRate->basisPoints()));
    }
}
