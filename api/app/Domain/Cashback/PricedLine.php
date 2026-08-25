<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Tax\FeeTax;

/**
 * One line of a lined credit AFTER pricing: the per-line §4 ceiling
 * integers, the terms it earned under, and why it priced that way.
 * priced_by: excluded | category | standing | promotion.
 *
 * `feeLaari` is Manfaa's NET fee on this line and `feeGstLaari` the tax on
 * it — the same meaning the columns carry on the transaction row. With GST
 * off (`feeGstBp = 0`, the platform today) the split is the identity and
 * these are exactly the integers TermsResolver produced.
 */
final readonly class PricedLine
{
    public function __construct(
        public ?int $categoryId,
        public ?string $slug,
        public ?string $nameEn,
        public int $amountLaari,
        public int $rateBp,
        public int $feeBp,
        public int $cashbackLaari,
        public int $feeLaari,
        public string $pricedBy,
        public int $sort,
        public int $feeGstBp = 0,
        public int $feeGstLaari = 0,
    ) {}

    /**
     * The same line, priced under a GST regime. Rounding happens HERE, per
     * line, so the transaction's header total stays the exact sum of the
     * stored line integers (§4).
     */
    public function withFeeTax(FeeTax $tax): self
    {
        [$net, $gst] = $tax->split($this->feeLaari);

        return new self(
            categoryId: $this->categoryId,
            slug: $this->slug,
            nameEn: $this->nameEn,
            amountLaari: $this->amountLaari,
            rateBp: $this->rateBp,
            feeBp: $this->feeBp,
            cashbackLaari: $this->cashbackLaari,
            feeLaari: $net,
            pricedBy: $this->pricedBy,
            sort: $this->sort,
            // Stamped even on a line that earned no fee at all (an excluded
            // category, a sub-laari rounding): the snapshot evidences the
            // terms the line met, exactly as rate_bp does on a zeroed row.
            feeGstBp: $tax->rateBp,
            feeGstLaari: $gst,
        );
    }
}
