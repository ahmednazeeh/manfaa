<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Marketplace\DeliveryQuote;
use App\Domain\Marketplace\SearchQuery;
use App\Domain\Money\Percent;
use App\Domain\Onboarding\MerchantLogo;
use App\Domain\Onboarding\OnboardingService;
use App\Http\Controllers\Controller;
use App\Models\BranchProduct;
use App\Models\CustomerAddress;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * PRODUCT search across every shop (`AI Product Search.png`).
 *
 * The marketplace was store-first: find the shop, then hunt its shelves.
 * That is a directory, not a marketplace — it makes the customer do the work
 * of knowing who sells what. A shopper wants rice; which shop has it is our
 * problem, not theirs.
 *
 * So this returns PRODUCTS, ranked across all stores, each carrying the shop
 * it comes from as an attribute rather than as a step. Browsing a single
 * store still exists and is reachable from any result — it is just no longer
 * the only road in.
 *
 * Public, like the rest of browse: nobody signs in to find out whether a
 * marketplace is worth signing in for.
 */
final class MarketSearchController extends Controller
{
    private const int LIMIT = 60;

    public function __construct(private readonly OnboardingService $onboarding) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address_id' => ['sometimes', 'nullable', 'integer'],
            'sort' => ['sometimes', 'in:relevance,price_asc,price_desc,rating,fastest'],
            'store' => ['sometimes', 'nullable', 'integer'],
        ]);

        $parsed = SearchQuery::parse((string) ($validated['q'] ?? ''));
        $address = $this->address($request, $validated['address_id'] ?? null);

        $listings = BranchProduct::query()
            ->with([
                'product.images',
                'branch.merchant.marketplace',
                'branch.deliveryRules',
            ])
            ->where('state', 'active')
            // Only shops actually open for business: trading, not paused, and
            // an approved vendor. Three conditions, none implying the others.
            ->whereHas('branch.merchant', fn ($query) => $query
                ->where('status', 'active')
                ->whereNull('unpublished_at')
                ->whereHas('marketplace', fn ($m) => $m->where('state', 'active')))
            ->when(
                $parsed->terms !== '',
                fn ($query) => $query->whereHas(
                    'product',
                    fn ($p) => $this->matchTerms($p, $parsed->terms),
                ),
            )
            ->when(
                $parsed->maxPriceLaari !== null,
                fn ($query) => $query->where('price_laari', '<=', $parsed->maxPriceLaari),
            )
            ->when(
                ($validated['store'] ?? null) !== null,
                fn ($query) => $query->where('branch_id', $validated['store']),
            )
            ->limit(self::LIMIT * 2)
            ->get();

        $rows = $listings
            ->map(fn (BranchProduct $listing) => $this->present($listing, $address))
            ->filter(fn (?array $row): bool => $row !== null)
            ->when(
                $parsed->minRating !== null,
                fn ($rows) => $rows->filter(
                    fn (array $row): bool => ($row['store']['rating'] ?? 0) >= $parsed->minRating,
                ),
            )
            ->values();

        $rows = $this->sort($rows, $validated['sort'] ?? 'relevance', $parsed)
            ->take(self::LIMIT)
            ->values();

        return new JsonResponse([
            'data' => $rows,
            'meta' => [
                'query' => $parsed->terms,
                // Exactly what we understood, so the chips on screen are a
                // readout rather than decoration.
                'facets' => $parsed->facets,
                'summary' => $parsed->summary($rows->count()),
                'total' => $rows->count(),
            ],
        ]);
    }

    /**
     * ONE product, as a shopper opens it (`AI Product Search.png` → detail).
     *
     * The store block is the point of this screen as much as the goods are:
     * a shopper deciding to buy is also deciding who to buy from, and
     * "visit store" belongs here rather than being a separate hunt.
     */
    public function show(Request $request, int $branchProduct): JsonResponse
    {
        $listing = BranchProduct::query()
            ->with([
                'product.images',
                'branch.merchant.marketplace',
                'branch.deliveryRules',
            ])
            ->where('state', 'active')
            ->whereKey($branchProduct)
            ->first();

        abort_if($listing === null, 404);

        $row = $this->present($listing, $this->address($request, null));

        abort_if($row === null, 404);

        $product = $listing->product;

        return new JsonResponse([
            'data' => $row + [
                'description' => $product?->description,
                'allow_substitutions' => (bool) ($product?->allow_substitutions ?? false),
                'stock_qty' => $listing->stock_qty,
                // Every picture, not just the first — a shopper looking at
                // one thing wants to see it properly.
                'images' => ($product?->images ?? collect())
                    ->sortBy('sort')
                    ->map(fn (ProductImage $image): string => Storage::disk(ProductImage::DISK)->url($image->path))
                    ->values(),
            ],
        ]);
    }

    /**
     * Every word must appear somewhere in the product — name, Dhivehi name,
     * description or SKU. AND rather than OR: "jasmine rice" should not
     * return every rice in the country.
     */
    private function matchTerms(mixed $query, string $terms): void
    {
        foreach (preg_split('/\s+/', $terms) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';

            $query->where(fn ($q) => $q
                ->where('name', 'ilike', $like)
                ->orWhere('name_dv', 'ilike', $like)
                ->orWhere('description', 'ilike', $like)
                ->orWhere('sku', 'ilike', $like));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function present(BranchProduct $listing, ?CustomerAddress $address): ?array
    {
        $product = $listing->product;
        $branch = $listing->branch;
        $merchant = $branch?->merchant;

        if ($product === null || $branch === null || $merchant === null) {
            return null;
        }

        $profile = $merchant->marketplace;
        $rule = $address?->zone_id === null
            ? null
            : $branch->deliveryRules->firstWhere('zone_id', $address->zone_id);

        // Quoted against an EMPTY basket: these are the chips a shopper
        // reads before adding anything.
        $terms = DeliveryQuote::for($rule, 0);
        $image = $product->images->sortBy('sort')->first();

        return [
            'branch_product_id' => $listing->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'name_dv' => $product->name_dv,
            'image_url' => $image instanceof ProductImage
                ? Storage::disk(ProductImage::DISK)->url($image->path)
                : null,
            'price_laari' => (int) $listing->price_laari,
            'compare_at_laari' => $listing->compare_at_laari,
            'in_stock' => $listing->stock_qty === null || $listing->stock_qty > 0,
            'cashback_rate_percent' => Percent::formatOrNull(
                $product->cashback_rate_bp ?? $this->onboarding->currentRateBp($merchant),
            ),
            // The shop as an ATTRIBUTE of the result, not a step before it.
            'store' => [
                'branch_id' => $branch->id,
                'merchant_id' => $merchant->id,
                'name' => $merchant->name,
                'branch_name' => $branch->name,
                'logo_url' => MerchantLogo::url((string) $merchant->slug, $merchant->logo_path),
                'rating' => $profile?->ratingAverage(),
                'rating_count' => (int) ($profile?->rating_count ?? 0),
            ],
            'delivery' => $terms->toArray(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sort(mixed $rows, string $sort, SearchQuery $parsed): mixed
    {
        return match ($sort) {
            'price_asc' => $rows->sortBy('price_laari'),
            'price_desc' => $rows->sortByDesc('price_laari'),
            'rating' => $rows->sortByDesc(fn (array $row): float => (float) ($row['store']['rating'] ?? 0)),
            'fastest' => $rows->sortBy(fn (array $row): int => $row['delivery']['eta_max'] ?? 9999),
            // Relevance, which is a blend rather than one column: cheapest
            // first when they asked for value, quickest when they asked for
            // speed, and otherwise a plain cheap-and-well-rated order. It is
            // deliberately simple and deliberately explicable — "Why these?"
            // has to have an answer.
            default => $parsed->bestValue
                ? $rows->sortBy('price_laari')
                : ($parsed->fastDelivery
                    ? $rows->sortBy(fn (array $row): int => $row['delivery']['eta_max'] ?? 9999)
                    : $rows->sortBy(fn (array $row): array => [
                        -1 * (float) ($row['store']['rating'] ?? 0),
                        $row['price_laari'],
                    ])),
        };
    }

    private function address(Request $request, ?int $id): ?CustomerAddress
    {
        $customer = $request->user('customer');

        if ($customer === null) {
            return null;
        }

        return CustomerAddress::query()
            ->where('customer_id', $customer->getKey())
            ->when($id !== null, fn ($q) => $q->whereKey($id))
            ->when($id === null, fn ($q) => $q->orderByDesc('is_default'))
            ->first();
    }
}
