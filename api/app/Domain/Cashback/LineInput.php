<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\MerchantProductCategory;

/**
 * One validated, resolved line of a lined credit, in submitted order.
 * $category null = the default "everything else" bucket (standing rate).
 * Built exclusively by LineSetParser, which owns the slug resolution and
 * the sum/distinctness rules.
 */
final readonly class LineInput
{
    public function __construct(
        public ?MerchantProductCategory $category,
        public int $amountLaari,
    ) {}
}
