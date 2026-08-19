<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Marketplace\DeliveryQuote;
use App\Domain\Money\Percent;
use App\Domain\Onboarding\OnboardingService;
use App\Http\Controllers\Controller;
use App\Models\BranchProduct;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\MarketplaceCategory;
use App\Models\MerchantBranch;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The Market tab (`Market View.png`, `Market View Tablet.png`).
 *
 * Lists BRANCHES, not merchants (§2.3): the branch is the storefront,
 * because stock and fulfilment are physical and a chain's two shops
 * genuinely differ on both. A card reads "Island Mart — Malé".
 *
 * Every delivery number here is a property of *branch → your address*, not
 * of the branch alone, which is why the address travels with the request.
 */
final class MarketController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    /** The storefronts a shopper can actually buy from today. */
    public function index(Request $request): JsonResponse
    {
        $address = $this->address($request);

        $branches = MerchantBranch::query()
            ->with(['merchant.marketplace', 'deliveryRules'])
            // A shop is only here if its business is trading, its owner has
            // not paused it, AND it is an approved vendor. Three separate
            // conditions, none implying the others.
            ->whereHas('merchant', fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('unpublished_at')
                ->whereHas('marketplace', fn ($m) => $m->where('state', 'active')))
            // ...and only if it has something to sell.
            ->whereHas('products', fn ($query) => $query->where('state', 'active'))
            ->get();

        $rows = $branches->map(function (MerchantBranch $branch) use ($address): array {
            $rule = $address?->zone_id === null
                ? null
                : $branch->deliveryRules->firstWhere('zone_id', $address->zone_id);

            // Quoted against an empty basket: these are the chips a shopper
            // reads BEFORE they have added anything.
            $quote = DeliveryQuote::for($rule, 0);
            $merchant = $branch->merchant;
            $profile = $merchant->marketplace;

            return [
                'branch_id' => $branch->id,
                'merchant_id' => $merchant->id,
                'store_name' => $merchant->name,
                'store_name_dv' => $merchant->name_dv,
                'branch_name' => $branch->name,
                'slug' => $merchant->slug,
                'address' => $branch->address,
                'cashback_rate_percent' => Percent::formatOrNull(
                    $this->onboarding->currentRateBp($merchant),
                ),
                'fulfilment' => $profile?->fulfilment,
                // Null, never 0.0 — a new shop has no rating, and showing it
                // zero stars would libel it on its first day (§11.2).
                'rating' => $profile?->ratingAverage(),
                'rating_count' => $profile?->rating_count ?? 0,
                'delivery' => $quote->toArray(),
                // A branch that cannot deliver to you is still worth showing:
                // you may collect. Never hidden, just labelled.
                'pickup_only' => ! $quote->delivers,
            ];
        });

        return new JsonResponse([
            'data' => $rows->values(),
            'meta' => [
                'address_id' => $address?->id,
                'needs_address' => $address === null,
            ],
        ]);
    }

    /** One storefront's shelves. */
    public function show(Request $request, int $branch): JsonResponse
    {
        $address = $this->address($request);

        $row = MerchantBranch::query()
            ->with(['merchant.marketplace', 'deliveryRules'])
            ->whereHas('merchant', fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('unpublished_at')
                ->whereHas('marketplace', fn ($m) => $m->where('state', 'active')))
            ->whereKey($branch)
            ->firstOrFail();

        $listings = BranchProduct::query()
            ->with(['product.images', 'product.marketplaceCategory'])
            ->where('branch_id', $row->id)
            ->where('state', 'active')
            ->whereHas('product', fn ($query) => $query->where('archived', false))
            ->when(
                $request->filled('category'),
                fn ($query) => $query->whereHas(
                    'product.marketplaceCategory',
                    fn ($c) => $c->where('slug', $request->string('category')),
                ),
            )
            ->get();

        $standingRateBp = $this->onboarding->currentRateBp($row->merchant);

        $rule = $address?->zone_id === null
            ? null
            : $row->deliveryRules->firstWhere('zone_id', $address->zone_id);

        return new JsonResponse(['data' => [
            'branch_id' => $row->id,
            'store_name' => $row->merchant->name,
            'branch_name' => $row->name,
            'address' => $row->address,
            'rating' => $row->merchant->marketplace?->ratingAverage(),
            'rating_count' => $row->merchant->marketplace?->rating_count ?? 0,
            'delivery' => DeliveryQuote::for($rule, 0)->toArray(),
            'cashback_rate_percent' => Percent::formatOrNull($standingRateBp),
            // Only the aisles this shop actually stocks — an empty category
            // chip is a promise the shelf cannot keep. These are the SHARED
            // marketplace aisles, never the merchant's cashback list: what a
            // shopper browses by is a shelf label, and what a line earns is
            // the merchant's own pricing. Showing the latter here would leak
            // one shop's rate structure to everybody.
            'categories' => $listings
                ->map(fn (BranchProduct $listing) => $listing->product->marketplaceCategory)
                ->filter()
                ->unique('id')
                ->sortBy('sort')
                ->map(fn (MarketplaceCategory $category): array => [
                    'slug' => $category->slug,
                    'name_en' => $category->name_en,
                    'name_dv' => $category->name_dv,
                ])->values(),
            'products' => $listings->map(fn (BranchProduct $listing): array => [
                'branch_product_id' => $listing->id,
                'product_id' => $listing->product->id,
                'name' => $listing->product->name,
                'name_dv' => $listing->product->name_dv,
                'description' => $listing->product->description,
                'price_laari' => $listing->price_laari,
                'compare_at_laari' => $listing->compare_at_laari,
                'cashback_rate_percent' => Percent::formatOrNull(
                    $listing->product->cashback_rate_bp ?? $standingRateBp,
                ),
                'image_url' => $listing->product->images->first() === null
                    ? null
                    : Storage::disk(ProductImage::DISK)
                        ->url($listing->product->images->first()->path),
                'in_stock' => $listing->isBuyable(),
                'category' => $listing->product->marketplaceCategory?->slug,
            ])->values(),
        ]]);
    }

    private function address(Request $request): ?CustomerAddress
    {
        $customer = $request->user('customer');

        if (! $customer instanceof Customer) {
            return null;
        }

        $requested = $request->integer('address_id');

        return $requested > 0
            ? $customer->addresses()->whereKey($requested)->first()
            : $customer->addresses()->where('is_default', true)->first();
    }
}
