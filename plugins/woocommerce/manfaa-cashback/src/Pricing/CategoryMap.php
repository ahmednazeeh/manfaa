<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Pricing;

use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Support\Options;
use WC_Product;

/**
 * WooCommerce product category → Manfaa category slug.
 *
 * The owner's rule: mapped categories are priced as mapped; everything
 * unmapped goes to the `category: null` bucket ("everything else", the
 * standing rate). A product in several WooCommerce categories that map to
 * different Manfaa categories is priced by the Manfaa category that
 * appears FIRST in the synced list — the merchant's own order — and the
 * position is read from the synced card, not stored with the mapping, so a
 * re-sync that reorders is honoured immediately.
 */
final class CategoryMap
{
    /** @param array<string, list<int>> $map  Manfaa slug => WooCommerce term ids */
    public function __construct(private readonly array $map, private readonly ?RateCard $card) {}

    public static function fromSettings(?RateCard $card = null): self
    {
        $raw = Options::get('category_map');
        $map = [];

        foreach (is_array($raw) ? $raw : [] as $slug => $terms) {
            $map[(string) $slug] = array_values(array_unique(array_map('intval', (array) $terms)));
        }

        return new self($map, $card ?? RateCard::cached());
    }

    public function enabled(): bool
    {
        return Options::string('pricing_mode') === Options::PRICING_PER_CATEGORY && $this->card !== null;
    }

    /** The Manfaa bucket for a product: a slug, or null for "everything else". */
    public function bucketFor(WC_Product $product): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        // Variations carry no categories of their own.
        $parentId = $product->get_parent_id() ?: $product->get_id();
        $terms = wc_get_product_term_ids($parentId, 'product_cat');

        if ($terms === []) {
            return null;
        }

        $best = null;
        $bestPosition = PHP_INT_MAX;

        foreach ($this->map as $slug => $termIds) {
            if (array_intersect($terms, $termIds) === []) {
                continue;
            }

            $category = $this->card?->category($slug);

            // A mapping whose category vanished from the sync no longer
            // prices anything — flagged in settings, ignored here.
            if ($category === null) {
                continue;
            }

            if ($category['position'] < $bestPosition) {
                $best = $slug;
                $bestPosition = $category['position'];
            }
        }

        return $best;
    }

    /** Mapped slugs the current card no longer knows — shown as a warning. */
    public function orphaned(): array
    {
        if ($this->card === null) {
            return [];
        }

        return array_values(array_filter(array_keys($this->map), fn (string $slug) => $this->card->category($slug) === null));
    }

    /** @return array<string, list<int>> */
    public function all(): array
    {
        return $this->map;
    }
}
