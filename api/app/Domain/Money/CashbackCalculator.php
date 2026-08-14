<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * §4 computation. Always round UP to the next laari at the line via ceiling
 * integer division (+9999 before intdiv by 10000) — customer-favourable, and
 * it leaves no fractional edge cases. Batch totals are always the sum of
 * rounded lines, never recomputed here.
 */
final class CashbackCalculator
{
    /**
     * $feeBpOverride carries a fee resolved from an effective-dated
     * fee_tier_schedules row (TermsResolver's path); when null, the static
     * §4 FeeTier map answers — identical behaviour for every existing caller.
     */
    public function calculate(Laari $eligible, Rate $cashbackRate, ?int $feeBpOverride = null): CashbackResult
    {
        if ($eligible->isNegative()) {
            throw new InvalidArgumentException('Eligible amount must not be negative.');
        }

        $rateBp = $cashbackRate->basisPoints();
        $feeBp = $feeBpOverride === null
            ? FeeTier::feeBpFor($rateBp)
            : Rate::fee($feeBpOverride)->basisPoints();

        return new CashbackResult(
            cashbackLaari: intdiv($eligible->value() * $rateBp + 9999, 10000),
            feeLaari: intdiv($eligible->value() * $feeBp + 9999, 10000),
            rateBp: $rateBp,
            feeBp: $feeBp,
        );
    }

    /**
     * Promotional-cap path. When the cap clips the reward, the fee follows the
     * reward actually granted: fee = ceil(cashback * fee_bp / rate_bp), done as
     * intdiv(cashback * fee_bp + rate_bp - 1, rate_bp). When the cap does not
     * clip, the normal per-line result stands untouched.
     */
    public function calculateCapped(Laari $eligible, Rate $cashbackRate, Laari $capRemaining, ?int $feeBpOverride = null): CashbackResult
    {
        if ($capRemaining->isNegative()) {
            throw new InvalidArgumentException('Cap remaining must not be negative.');
        }

        $normal = $this->calculate($eligible, $cashbackRate, $feeBpOverride);

        if ($normal->cashbackLaari <= $capRemaining->value()) {
            return $normal;
        }

        $cashback = $capRemaining->value();

        return new CashbackResult(
            cashbackLaari: $cashback,
            feeLaari: intdiv($cashback * $normal->feeBp + $normal->rateBp - 1, $normal->rateBp),
            rateBp: $normal->rateBp,
            feeBp: $normal->feeBp,
        );
    }
}
