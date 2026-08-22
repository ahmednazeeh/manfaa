<?php

declare(strict_types=1);

namespace Manfaa\Cashback\Pricing;

use Manfaa\Cashback\Money\Laari;
use Manfaa\Cashback\Support\Options;
use WC_Order;
use WC_Order_Item_Product;

/**
 * An order → `eligible_amount`, `sale_amount` and `lines[]`.
 *
 * Eligible = the sum of line totals after coupon discounts, excluding
 * shipping and fees; GST included or not per the merchant's awarding
 * policy (owner decision 2026-08-22). Per item: `get_total()` (+ tax under
 * the inclusive policy) rounded to 2 dp first, then to laari; summed per
 * Manfaa bucket; zero buckets dropped; `eligible_amount` = the sum of the
 * buckets, so the partition identity the server checks always holds.
 *
 * `sale_amount` = the order total, what the buyer actually paid.
 */
final class LineBuilder
{
    public function __construct(private readonly CategoryMap $map) {}

    /**
     * @param  bool  $netOfRefunds  subtract what has been refunded per line —
     *                              the partial-refund amend prices what the
     *                              buyer KEPT
     * @return array{eligible_laari:int, sale_laari:int, buckets:array<string,int>, lines:?list<array{category:?string,amount_laari:int}>}
     */
    public function build(WC_Order $order, bool $netOfRefunds = false): array
    {
        $buckets = [];
        $incTax = Options::string('awarding_policy') === Options::POLICY_ITEMS_INC_TAX;

        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }

            $amount = (float) $item->get_total();

            if ($incTax) {
                $amount += (float) $item->get_total_tax();
            }

            if ($netOfRefunds) {
                // Refund totals come back positive from these accessors.
                $amount -= (float) $order->get_total_refunded_for_item($item->get_id());

                if ($incTax) {
                    foreach ($item->get_taxes()['total'] ?? [] as $taxId => $unused) {
                        $amount -= (float) $order->get_tax_refunded_for_item($item->get_id(), (int) $taxId);
                    }
                }
            }

            $laari = Laari::fromDecimal($amount);

            if ($laari <= 0) {
                continue;
            }

            $product = $item->get_product();
            $bucket = $product ? $this->map->bucketFor($product) : null;
            $key = $bucket ?? '';

            $buckets[$key] = ($buckets[$key] ?? 0) + $laari;
        }

        $eligible = array_sum($buckets);
        $lines = null;

        if ($this->map->enabled()) {
            $lines = [];

            foreach ($buckets as $key => $laari) {
                $lines[] = ['category' => $key === '' ? null : (string) $key, 'amount_laari' => $laari];
            }
        }

        $saleLaari = Laari::fromDecimal((float) $order->get_total() - ($netOfRefunds ? (float) $order->get_total_refunded() : 0.0));

        return [
            'eligible_laari' => $eligible,
            'sale_laari' => $saleLaari,
            'buckets' => $buckets,
            'lines' => $lines,
        ];
    }

    /**
     * The same partition for a cart — what the estimate uses.
     *
     * @return array<string, int>
     */
    public function cartBuckets(\WC_Cart $cart): array
    {
        $buckets = [];
        $incTax = Options::string('awarding_policy') === Options::POLICY_ITEMS_INC_TAX;

        foreach ($cart->get_cart() as $row) {
            $product = $row['data'] ?? null;

            if (! $product instanceof \WC_Product) {
                continue;
            }

            $amount = (float) ($row['line_total'] ?? 0);

            if ($incTax) {
                $amount += (float) ($row['line_tax'] ?? 0);
            }

            $laari = Laari::fromDecimal($amount);

            if ($laari <= 0) {
                continue;
            }

            $key = $this->map->bucketFor($product) ?? '';
            $buckets[$key] = ($buckets[$key] ?? 0) + $laari;
        }

        return $buckets;
    }
}
