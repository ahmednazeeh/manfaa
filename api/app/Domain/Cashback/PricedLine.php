<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

/**
 * One line of a lined credit AFTER pricing: the per-line §4 ceiling
 * integers, the terms it earned under, and why it priced that way.
 * priced_by: excluded | category | standing | promotion.
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
    ) {}
}
