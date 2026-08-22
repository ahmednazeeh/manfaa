<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Storefront;

use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Money\Laari;
use Manfaa\Cashback\Pricing\CategoryMap;
use Manfaa\Cashback\Support\Options;
use WC_Product;

/**
 * "Earn up to MVR x cashback" under the price on a product page (display
 * option, off by default). "Up to" because the store's minimum and any
 * promotion are decided on the whole order, not this line.
 */
final class Badge
{
    public static function hooks(): void
    {
        add_action('woocommerce_single_product_summary', [self::class, 'render'], 11);
    }

    public static function render(): void
    {
        if (! Options::bool('show_product_badge') || get_woocommerce_currency() !== 'MVR') {
            return;
        }

        global $product;

        if (! $product instanceof WC_Product) {
            return;
        }

        $laari = self::forProduct($product);

        if ($laari === null || $laari <= 0) {
            return;
        }

        printf(
            '<p class="manfaa-badge-product">%s</p>',
            esc_html(sprintf(
                /* translators: %s: amount */
                __('Earn up to MVR %s Manfaa cashback', 'manfaa-cashback'),
                Laari::toMvr($laari),
            )),
        );
    }

    /** The cashback one unit at the current price would earn in its bucket, or null without a rate card. */
    public static function forProduct(WC_Product $product): ?int
    {
        $card = RateCard::cached();

        if ($card === null) {
            return null;
        }

        $price = Laari::fromDecimal((float) wc_get_price_to_display($product));

        if ($price <= 0) {
            return null;
        }

        $bucket = CategoryMap::fromSettings($card)->bucketFor($product);

        return Laari::cashback($price, $card->bucketRateBp($bucket));
    }
}
