<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * §4 computation. Round half-up at the line via integer division with +5000;
 * batch totals are always the sum of rounded lines, never recomputed here.
 */
final class CashbackCalculator
{
    public function calculate(Laari $eligible, Rate $cashbackRate): CashbackResult
    {
        $rateBp = $cashbackRate->basisPoints();
        $feeBp = FeeTier::feeBpFor($rateBp);

        return new CashbackResult(
            cashbackLaari: intdiv($eligible->value() * $rateBp + 5000, 10000),
            feeLaari: intdiv($eligible->value() * $feeBp + 5000, 10000),
            rateBp: $rateBp,
            feeBp: $feeBp,
        );
    }

    /**
     * Promotional-cap path. When the cap clips the reward, the fee follows the
     * reward actually granted: fee = round-half-up(cashback * fee_bp / rate_bp),
     * done as intdiv(cashback * fee_bp + intdiv(rate_bp, 2), rate_bp).
     * When the cap does not clip, the normal per-line result stands untouched.
     */
    public function calculateCapped(Laari $eligible, Rate $cashbackRate, Laari $capRemaining): CashbackResult
    {
        $normal = $this->calculate($eligible, $cashbackRate);

        if ($normal->cashbackLaari <= $capRemaining->value()) {
            return $normal;
        }

        $cashback = $capRemaining->value();

        return new CashbackResult(
            cashbackLaari: $cashback,
            feeLaari: intdiv($cashback * $normal->feeBp + intdiv($normal->rateBp, 2), $normal->rateBp),
            rateBp: $normal->rateBp,
            feeBp: $normal->feeBp,
        );
    }
}
