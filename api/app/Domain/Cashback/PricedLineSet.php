<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Tax\FeeTax;

/**
 * The full pricing outcome of a lined credit. Transaction totals are the
 * SUM of the stored per-line integers — never recomputed on aggregates
 * (§4). rowRateBp/rowFeeBp are the STANDING resolution frozen onto the
 * transaction row exactly as the single-rate path freezes it: row-level bp
 * are the base-rate snapshot; per-line truth lives in the lines.
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
        );
    }
}
