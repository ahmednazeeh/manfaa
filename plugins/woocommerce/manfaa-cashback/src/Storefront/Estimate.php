<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Storefront;

use Manfaa\Cashback\Api\RateCard;
use Manfaa\Cashback\Money\Estimator;
use Manfaa\Cashback\Money\Laari;
use Manfaa\Cashback\Pricing\CategoryMap;
use Manfaa\Cashback\Pricing\LineBuilder;
use Manfaa\Cashback\Support\Options;

/**
 * What the storefront shows about the current cart, in one array that both
 * the Blocks endpoint data and the classic panel read.
 */
final class Estimate
{
    /**
     * @return array{available:bool, eligible_laari:int, estimate_laari:int, shortfall_laari:int, estimate_mvr:string, shortfall_mvr:string, wording:string}
     */
    public static function forCart(?\WC_Cart $cart): array
    {
        $none = ['available' => false, 'eligible_laari' => 0, 'estimate_laari' => 0, 'shortfall_laari' => 0, 'estimate_mvr' => '0.00', 'shortfall_mvr' => '0.00', 'wording' => ''];

        if ($cart === null || ! Options::bool('show_estimate') || get_woocommerce_currency() !== 'MVR') {
            return $none;
        }

        $card = RateCard::cached();

        if ($card === null) {
            return $none;
        }

        $buckets = (new LineBuilder(CategoryMap::fromSettings($card)))->cartBuckets($cart);
        $result = Estimator::estimate($card, $buckets);

        return [
            'available' => true,
            'eligible_laari' => $result['eligible_laari'],
            'estimate_laari' => $result['estimate_laari'],
            'shortfall_laari' => $result['shortfall_laari'],
            'estimate_mvr' => Laari::toMvr($result['estimate_laari']),
            'shortfall_mvr' => Laari::toMvr($result['shortfall_laari']),
            'wording' => self::wording(),
        ];
    }

    public static function wording(): string
    {
        $custom = trim(Options::string('estimate_wording'));

        return $custom !== '' ? $custom : __('Estimated Manfaa cashback', 'manfaa-cashback');
    }

    /**
     * Dhivehi is right-to-left. `is_rtl()` only knows that from a core
     * language pack, and there is none for `dv` — so a site whose locale
     * is Dhivehi gets the panel mirrored regardless.
     */
    public static function rtl(): bool
    {
        return is_rtl() || str_starts_with(strtolower((string) determine_locale()), 'dv');
    }

    public static function label(): string
    {
        $custom = trim(Options::string('panel_label'));

        return $custom !== '' ? $custom : __('Manfaa code', 'manfaa-cashback');
    }
}
