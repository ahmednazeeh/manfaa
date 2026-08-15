<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

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
}
