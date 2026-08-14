<?php

declare(strict_types=1);

namespace App\Domain\Money;

use OutOfRangeException;

/**
 * Resolves the platform fee from the customer cashback rate (§4 tier table).
 */
final class FeeTier
{
    /**
     * The static map covers the full structural range 50–2000 bp. It is the
     * FALLBACK only — it prices a rate when NO fee_tier_schedules row exists
     * (empty or not-yet-migrated table). Once any schedule row is active,
     * that schedule's own ceiling governs which rates are sellable.
     */
    public const int CEILING_BP = Rate::MAX_CASHBACK_BP;

    public static function feeBpFor(int $cashbackBp): int
    {
        return match (true) {
            $cashbackBp >= 50 && $cashbackBp <= 99 => 25,
            $cashbackBp >= 100 && $cashbackBp <= 199 => 50,
            $cashbackBp >= 200 && $cashbackBp <= 499 => 75,
            $cashbackBp >= 500 && $cashbackBp <= self::CEILING_BP => 100,
            default => throw new OutOfRangeException(
                sprintf('No fee tier for %d basis points; cashback rates are 50-%d.', $cashbackBp, self::CEILING_BP)
            ),
        };
    }

    public static function feeFor(Rate $cashbackRate): Rate
    {
        return Rate::fee(self::feeBpFor($cashbackRate->basisPoints()));
    }
}
