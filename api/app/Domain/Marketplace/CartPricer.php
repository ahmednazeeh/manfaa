<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\Money\Percent;
use App\Domain\Onboarding\OnboardingService;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerAddress;
use App\Models\MerchantBranch;
use Illuminate\Support\Collection;

/**
 * The whole cart screen, priced in one pass
 * (`Cart Page Collapsible By Merchant.png`, `Cart Page Expanded.png`).
 *
 * Everything the shopper reads about money comes from here — the per-shop
 * subtotal, the delivery fee and whether it was waived, the distance to a
 * minimum, the cashback they will earn, and the total. One pass, one set of
 * numbers, so the collapsed card and the expanded card can never disagree.
 *
 * PRICED LIVE, from the listing as it stands right now. The price stored on
 * the cart item is kept only so the screen can SAY a price moved; it is
 * never what we charge. A basket that quietly bills yesterday's price is
 * worse than one that says the price changed.
 */
final readonly class CartPricer
{
    public function __construct(private OnboardingService $onboarding) {}

    /**
     * @return array<string, mixed> the wire shape the cart screen renders
     */
    public function price(Cart $cart, ?CustomerAddress $address): array
    {
        $items = $cart->items()
            ->with([
                'listing.product.merchant',
                'listing.branch.deliveryRules',
            ])
            ->get();

        // Group by the SHOP, which is what a subcart is: the branch is the
        // storefront (§2.3), so two branches of one chain are two cards.
        $groups = $items->groupBy(fn (CartItem $item): int => (int) $item->listing->branch_id);

        $subcarts = [];
        $itemsTotal = 0;
        $deliveryTotal = 0;
        $cashbackTotal = 0;
        $allMinimumsMet = true;

        foreach ($groups as $branchId => $lines) {
            $branch = $lines->first()->listing->branch;
            $subcart = $this->priceSubcart($branch, $lines, $address);

            $itemsTotal += $subcart['items_laari'];
            $deliveryTotal += $subcart['delivery']['fee_laari'];
            $cashbackTotal += $subcart['cashback_laari'];
            $allMinimumsMet = $allMinimumsMet && $subcart['delivery']['minimum_met'];

            $subcarts[] = $subcart;
        }

        return [
            'subcarts' => $subcarts,
            'items_laari' => $itemsTotal,
            'delivery_laari' => $deliveryTotal,
            'total_payable_laari' => $itemsTotal + $deliveryTotal,
            'cashback_laari' => $cashbackTotal,
            'store_count' => count($subcarts),
            // The checkout button is disabled on this, and the screen names
            // WHICH shop is short rather than refusing without saying why.
            'can_checkout' => $subcarts !== [] && $allMinimumsMet,
            'address_id' => $address?->id,
            // No address yet is not an error — the customer may be browsing
            // before they have said where they are. Delivery simply cannot
            // be quoted until they do.
            'needs_address' => $address === null,
        ];
    }

    /**
     * @param  Collection<int, CartItem>  $lines
     * @return array<string, mixed>
     */
    private function priceSubcart(MerchantBranch $branch, $lines, ?CustomerAddress $address): array
    {
        $merchant = $lines->first()->listing->product->merchant;
        $standingRateBp = $this->onboarding->currentRateBp($merchant) ?? 0;

        $itemsLaari = 0;
        $cashbackLaari = 0;
        $rows = [];

        foreach ($lines as $line) {
            $listing = $line->listing;
            $product = $listing->product;

            // LIVE price, always. The stored one only tells the screen that
            // something moved.
            $unit = (int) $listing->price_laari;
            $lineTotal = $unit * $line->qty;

            // The product's own rate when it has one, otherwise whatever the
            // store advertises. Ceiling rounding, per the money law.
            $rateBp = $product->cashback_rate_bp ?? $standingRateBp;
            // The §10 ceiling, computed once on the line total — the same
            // arithmetic CashbackCalculator does for an in-store sale.
            $lineCashback = intdiv($lineTotal * $rateBp + 9999, 10000);

            $itemsLaari += $lineTotal;
            $cashbackLaari += $lineCashback;

            $rows[] = [
                'cart_item_id' => $line->id,
                'branch_product_id' => $listing->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'name_dv' => $product->name_dv,
                'qty' => $line->qty,
                'unit_price_laari' => $unit,
                'line_total_laari' => $lineTotal,
                'cashback_laari' => $lineCashback,
                // Said out loud rather than applied silently.
                'price_changed' => $unit !== (int) $line->unit_price_laari,
                'price_was_laari' => $unit !== (int) $line->unit_price_laari
                    ? (int) $line->unit_price_laari
                    : null,
                // Still buyable? An unavailable line stays ON the screen and
                // is flagged — a row that vanishes reads as a bug, and the
                // shopper cannot act on what they cannot see.
                'available' => $listing->isBuyable(),
                'stock_qty' => $listing->stock_qty,
            ];
        }

        $rule = $address?->zone_id === null
            ? null
            : $branch->deliveryRules->firstWhere('zone_id', $address->zone_id);

        $quote = DeliveryQuote::for($rule, $itemsLaari);

        return [
            'branch_id' => $branch->id,
            'merchant_id' => $merchant->id,
            // Brand AND island — "Island Mart — Malé", never a bare branch id.
            'store_name' => $merchant->name,
            'branch_name' => $branch->name,
            'items' => $rows,
            'items_laari' => $itemsLaari,
            'cashback_laari' => $cashbackLaari,
            'cashback_rate_percent' => Percent::format($standingRateBp),
            'delivery' => $quote->toArray(),
            // Every line has to be buyable before this shop can be checked
            // out — see the per-item `available` flag for which one is not.
            'all_available' => collect($rows)->every(fn (array $row): bool => $row['available']),
        ];
    }
}
