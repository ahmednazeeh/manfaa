<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Platform\FeePromotionKind;
use App\Domain\Platform\FeeRelief;
use App\Domain\Tax\FeeTax;

/**
 * The platform fee one priced unit — a whole unlined sale, or the base-rate
 * snapshot of a lined one — was charged, and what it WOULD have been charged
 * without a promotion.
 *
 * Four numbers, and the last two are the entire point of the fee-promotions
 * round: `listFeeLaari` is the "before" price and `feeLaari` the "after", so
 * `forgoneLaari()` is what Manfaa spent to acquire this merchant on this
 * sale. When no promotion applied the two are the same integer and the
 * forgone figure is 0 — which is true of every sale on the platform before
 * 2026-08-25.
 *
 * BOTH FEE FIGURES ARE ON THE SAME SIDE OF GST. `withFeeTax()` re-expresses
 * the pair under a tax regime by splitting EACH of them, so the difference
 * stays a difference between two NET fees — the same basis `fee_laari` and
 * ledger account 4100 already carry. Splitting only the charged fee would
 * make the forgone figure a mix of gross and net and quietly overstate what
 * the promotion cost.
 */
final readonly class PricedFee
{
    public function __construct(
        public FeeRelief $relief,
        /** What the §4 tier would have charged, in basis points. */
        public int $tierFeeBp,
        /** What was actually charged: min(promotion, tier). */
        public int $chargedFeeBp,
        /** The fee at $tierFeeBp, in laari. */
        public int $listFeeLaari,
        /** The fee at $chargedFeeBp, in laari. */
        public int $feeLaari,
    ) {}

    /** No promotion at all — the identity, used everywhere nothing applies. */
    public static function none(int $tierFeeBp, int $feeLaari): self
    {
        return new self(FeeRelief::none(), $tierFeeBp, $tierFeeBp, $feeLaari, $feeLaari);
    }

    /** Did a promotion actually make this unit cheaper? */
    public function reduced(): bool
    {
        return $this->chargedFeeBp < $this->tierFeeBp;
    }

    public function kind(): ?FeePromotionKind
    {
        return $this->reduced() ? $this->relief->kind : null;
    }

    /** The stamp's `list_fee_bp`: the "before" price, or NULL when there was none. */
    public function listFeeBp(): ?int
    {
        return $this->reduced() ? $this->tierFeeBp : null;
    }

    /** The net fee revenue given up on this unit. Never negative. */
    public function forgoneLaari(): int
    {
        return max(0, $this->listFeeLaari - $this->feeLaari);
    }

    /**
     * The same pair under a GST regime: each fee split, the NET half kept.
     * With GST off this is the identity (FeeTax::split at 0 bp returns the
     * fee untouched), so a sale today prices byte for byte as it would have.
     */
    public function withFeeTax(FeeTax $tax): self
    {
        if (! $tax->applies()) {
            return $this;
        }

        return new self(
            $this->relief,
            $this->tierFeeBp,
            $this->chargedFeeBp,
            $tax->netOf($this->listFeeLaari),
            $tax->netOf($this->feeLaari),
        );
    }
}
