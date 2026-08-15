<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\Laari;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;

/**
 * Resolves and validates the raw lines[] payload of a lined credit —
 * shared verbatim by the manual panel path and POST /v1/transactions so
 * both enforce identical rules and answer identical machine codes.
 *
 * Line contract (documented in docs/openapi.yaml and the integration
 * guide): each line is {category: <slug>|null, amount_laari: int >= 1}.
 * `category: null` is the explicit default "everything else" bucket,
 * priced at the standing rate — a JSON null can never collide with a
 * merchant-created slug the way a reserved word like "other" could.
 *
 * Rules, in checking order per line then across the set:
 *  - the slug must name one of THIS merchant's categories
 *    (unknown_category — another merchant's slug is indistinguishable
 *    from a nonexistent one by design);
 *  - the category must be active (inactive_category);
 *  - each category, and the default bucket, may appear at most once
 *    (duplicate_category_line);
 *  - SUM(amount_laari) must equal eligible_amount (lines_sum_mismatch).
 */
final readonly class LineSetParser
{
    /**
     * @param  list<array{category: string|null, amount_laari: int|string}>  $raw
     * @return list<LineInput>
     *
     * @throws LinePricingException
     */
    public function parse(Merchant $merchant, array $raw, Laari $eligible): array
    {
        $slugs = array_values(array_unique(array_filter(
            array_map(fn (array $row): ?string => $row['category'] ?? null, $raw),
            fn (?string $slug): bool => $slug !== null,
        )));

        $categories = MerchantProductCategory::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $seen = [];
        $sum = 0;
        $lines = [];

        foreach ($raw as $row) {
            $slug = $row['category'] ?? null;
            $key = $slug ?? "\0default";

            if (isset($seen[$key])) {
                throw LinePricingException::duplicateCategoryLine($slug);
            }

            $seen[$key] = true;

            $category = null;

            if ($slug !== null) {
                $category = $categories->get($slug)
                    ?? throw LinePricingException::unknownCategory($slug);

                if (! $category->active) {
                    throw LinePricingException::inactiveCategory($slug);
                }
            }

            $amount = (int) $row['amount_laari'];
            $sum += $amount;
            $lines[] = new LineInput($category, $amount);
        }

        if ($sum !== $eligible->value()) {
            throw LinePricingException::linesSumMismatch($sum, $eligible->value());
        }

        return $lines;
    }
}
