<?php

declare(strict_types=1);

use App\Models\BranchProduct;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantMarketplaceProfile;
use App\Models\MerchantRate;
use App\Models\Order;
use App\Models\Product;
use App\Models\Suborder;
use App\Models\Zone;

/*
 * Shared marketplace fixtures.
 *
 * A vendor is four things at once — an approved merchant, an enrolled
 * marketplace profile, a branch, and something on its shelf — and every
 * marketplace suite needs all four. Building them inline in each file is
 * how the four drift apart.
 */

if (! function_exists('marketZone')) {
    /** A real polygon, so a pin dropped inside it genuinely resolves. */
    function marketZone(): Zone
    {
        return Zone::create(['name' => "Male'", 'polygon' => [
            ['lat' => 4.16, 'lng' => 73.49],
            ['lat' => 4.18, 'lng' => 73.49],
            ['lat' => 4.18, 'lng' => 73.52],
            ['lat' => 4.16, 'lng' => 73.52],
        ]]);
    }
}

if (! function_exists('vendor')) {
    /**
     * A shop that can actually be bought from: trading merchant, approved
     * vendor, one branch, one product on the shelf, one live rate.
     *
     * @return array{merchant: Merchant, branch: MerchantBranch, product: Product, listing: BranchProduct}
     */
    function vendor(string $name, int $rateBp = 200, int $priceLaari = 10000): array
    {
        $merchant = Merchant::factory()->create(['status' => 'active', 'name' => $name]);
        MerchantMarketplaceProfile::factory()->for($merchant)->create();
        MerchantRate::factory()->for($merchant)->create([
            'rate_bp' => $rateBp,
            'effective_from' => now()->subYear(),
            'effective_to' => null,
        ]);

        $branch = MerchantBranch::factory()->for($merchant)->create(['name' => 'Malé']);
        $product = Product::factory()->for($merchant)->create(['name' => $name.' Rice']);
        $listing = BranchProduct::factory()->create([
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'price_laari' => $priceLaari,
            'stock_qty' => 50,
            'state' => 'active',
        ]);

        return compact('merchant', 'branch', 'product', 'listing');
    }
}

if (! function_exists('payFor')) {
    /**
     * Mark an order's payment verified, as an admin (or the bank-history
     * matcher) does before any shop ever sees it.
     *
     * Every fulfilment test needs this now: since the 2026-08-19 audit,
     * nothing may be accepted, advanced, rejected or amended until the money
     * has actually arrived. Tests that skip it are asserting against a state
     * a shop is never shown.
     */
    function payFor(Suborder $suborder): Suborder
    {
        Order::query()
            ->whereKey($suborder->order_id)
            ->update([
                'payment_state' => 'verified',
                'verified_at' => now(),
                'state' => 'under_review',
            ]);

        return $suborder->refresh();
    }
}
