<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;
use OutOfRangeException;

/**
 * An integer count of basis points (2% = 200). The int-typed factories are
 * the only way in, so non-integers are rejected by the type system.
 */
final readonly class Rate
{
    private function __construct(private int $basisPoints) {}

    /**
     * The structural cashback ceiling (§4): 20%. NOTE — a rate is only
     * SELLABLE up to the active fee tier schedule's own ceiling
     * (TierScheduleService::activeCeiling()); this bound is the absolute
     * limit no schedule may ever price beyond.
     */
    public const int MAX_CASHBACK_BP = 2000;

    /**
     * The structural cashback floor (§4): 0.50%. Named so the wire parser
     * (Percent) bounds a submitted percent against the same law.
     */
    public const int MIN_CASHBACK_BP = 50;

    /**
     * The smallest expressible platform fee: 1 bp (0.01%).
     */
    public const int MIN_FEE_BP = 1;

    /**
     * Customer cashback rates: 50–2000 bp inclusive, per the §4 tier table.
     */
    public static function cashback(int $basisPoints): self
    {
        if ($basisPoints < self::MIN_CASHBACK_BP || $basisPoints > self::MAX_CASHBACK_BP) {
            throw new OutOfRangeException(
                sprintf('Cashback rate must be 50-%d basis points, got %d.', self::MAX_CASHBACK_BP, $basisPoints)
            );
        }

        return new self($basisPoints);
    }

    /**
     * Platform fee rates: any positive integer up to 2000 bp. Historically
     * exactly {25, 50, 75, 100} (the static §4 tier map); admin-managed fee
     * tier schedules make arbitrary integer fees legal — bounded above by
     * the cashback ceiling, since a schedule never lets fee_bp exceed the
     * cashback rate it applies to.
     */
    public static function fee(int $basisPoints): self
    {
        if ($basisPoints < self::MIN_FEE_BP || $basisPoints > self::MAX_CASHBACK_BP) {
            throw new InvalidArgumentException(
                sprintf('Fee rate must be %d-%d basis points, got %d.', self::MIN_FEE_BP, self::MAX_CASHBACK_BP, $basisPoints)
            );
        }

        return new self($basisPoints);
    }

    /**
     * A fee AS CHARGED on one sale: 0–2000 bp.
     *
     * Identical to fee() but for the floor, and the floor is the whole
     * point. `fee()` guards a fee somebody SETS — a tier band, a wire value
     * — and a tier fee of zero would be a price list that charges nothing,
     * which no schedule may express (TierSchedule requires every band's
     * fee_bp >= 1). This guards a fee somebody PAYS, and since the platform
     * fee promotions of 2026-08-25 a merchant may legitimately pay nothing:
     * "0 fee during first X days" is the feature, not an error.
     *
     * Nothing else changes. A promotional fee still comes from a superadmin
     * setting bounded by the same 20.00% ceiling, and the charged fee is
     * always min(promotion, tier), so this can only ever widen the range
     * DOWNWARD.
     */
    public static function chargedFee(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > self::MAX_CASHBACK_BP) {
            throw new InvalidArgumentException(
                sprintf('A charged fee must be 0-%d basis points, got %d.', self::MAX_CASHBACK_BP, $basisPoints)
            );
        }

        return new self($basisPoints);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }
}
