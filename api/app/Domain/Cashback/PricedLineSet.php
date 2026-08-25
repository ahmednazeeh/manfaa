<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Platform\FeePromotionKind;
use App\Domain\Tax\FeeTax;

/**
 * The full pricing outcome of a lined credit. Transaction totals are the
 * SUM of the stored per-line integers — never recomputed on aggregates
 * (§4). rowRateBp/rowFeeBp are the STANDING resolution frozen onto the
 * transaction row exactly as the single-rate path freezes it: row-level bp
 * are the base-rate snapshot; per-line truth lives in the lines.
 *
 * `feePromoKind` / `feePromoFeeBp` are the platform fee promotion the SALE
 * was priced under (2026-08-25) — a property of the sale, not of a line, in
 * exactly the way `fee_treatment` is: a line belongs to one transaction, and
 * a second copy could only ever disagree with the first. They are null
 * unless the promotion actually made something cheaper, so a promotion
 * running but beating nobody's tier leaves a row indistinguishable from one
 * priced with no promotion at all — which is what it is.
 *
 * `rowListFeeBp` is the matched pair of `rowFeeBp` and of nothing else: the
 * before-price of the BASE-RATE SNAPSHOT, null when the promotion did not
 * beat that snapshot's own tier fee. It is deliberately NOT "the before
 * price of this sale": on a lined sale where only a category line was
 * reduced it reads null while `feePromoKind` and `feeForgoneTotal()` say a
 * promotion priced the row — because the header's two fee columns have to be
 * two numbers costed on the SAME rate (AmendmentService re-prices an unlined
 * correction from exactly that pair), and each line already carries its own
 * before-price. FrozenFeePromotionTest pins the shape.
 */
final readonly class PricedLineSet
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public array $lines,
        public ?int $promotionId,
        public int $rowRateBp,
        public int $rowFeeBp,
        public ?FeePromotionKind $feePromoKind = null,
        public ?int $feePromoFeeBp = null,
        public ?int $rowListFeeBp = null,
    ) {}

    public function cashbackTotal(): int
    {
        return array_sum(array_map(fn (PricedLine $line): int => $line->cashbackLaari, $this->lines));
    }

    public function feeTotal(): int
    {
        return array_sum(array_map(fn (PricedLine $line): int => $line->feeLaari, $this->lines));
    }

    public function feeGstTotal(): int
    {
        return array_sum(array_map(fn (PricedLine $line): int => $line->feeGstLaari, $this->lines));
    }

    /**
     * The header's forgone fee: the SUM of the stored line integers, like
     * every other header total on a lined credit — never a second,
     * differently rounded computation over the aggregate.
     */
    public function feeForgoneTotal(): int
    {
        return array_sum(array_map(fn (PricedLine $line): int => $line->forgoneFeeLaari(), $this->lines));
    }

    /**
     * The same set, re-expressed under a GST regime.
     *
     * Applied per LINE and never to the header figure, so `feeTotal()` and
     * `feeGstTotal()` remain the exact sums the stored rows will carry — the
     * header can never disagree with its own lines by a laari of rounding.
     *
     * With GST off this is the identity: FeeTax::split at 0 bp returns the
     * fee untouched and no tax, so a lined credit today prices byte for byte
     * as it did before this existed.
     */
    public function withFeeTax(FeeTax $tax): self
    {
        if (! $tax->applies()) {
            return $this;
        }

        return new self(
            array_map(fn (PricedLine $line): PricedLine => $line->withFeeTax($tax), $this->lines),
            $this->promotionId,
            $this->rowRateBp,
            $this->rowFeeBp,
            $this->feePromoKind,
            $this->feePromoFeeBp,
            $this->rowListFeeBp,
        );
    }
}
