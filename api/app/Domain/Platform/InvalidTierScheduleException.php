<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use InvalidArgumentException;

/**
 * A new fee tier schedule was rejected: malformed tiers (gap, overlap,
 * out-of-range, fee above rate — validated by TierSchedule::fromArray), an
 * effective_from too soon, or a ceiling below a rate already sold for the
 * schedule's coverage window. Schedules must be dated at least one hour into
 * the future so in-flight transactions are never repriced ambiguously.
 */
final class InvalidTierScheduleException extends InvalidArgumentException
{
    public static function effectiveFromTooSoon(): self
    {
        return new self('effective_from must be at least one hour in the future — in-flight transactions are never repriced.');
    }

    /**
     * The schedule's ceiling would strand an already-sold rate: a standing
     * merchant rate or a published (immutable, uncancellable) promotion in
     * force during the schedule's coverage would have no priced fee, and
     * every credit for it would fail (TierScheduleService coverage
     * invariant).
     */
    public static function ceilingBelowInForceRates(int $ceilingBp, int $maxInForceBp): self
    {
        return new self(sprintf(
            'This schedule prices rates only up to %s%%, but a standing merchant rate or published promotion of %s%% is already in force (or scheduled) during its coverage — its credits could no longer be priced. Cover at least %s%%.',
            self::percent($ceilingBp),
            self::percent($maxInForceBp),
            self::percent($maxInForceBp),
        ));
    }

    /**
     * Exact 2dp percent from integer basis points — pure integer math,
     * never floats (money law): 1000 -> "10.00", 1550 -> "15.50".
     */
    private static function percent(int $bp): string
    {
        return sprintf('%d.%02d', intdiv($bp, 100), $bp % 100);
    }
}
