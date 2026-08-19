<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Approvals\ChangeRequestService;
use App\Domain\Money\Percent;
use App\Http\Controllers\Controller;
use App\Models\BranchProduct;
use App\Models\MarketplaceCategory;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantUser;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The merchant's catalogue (PLAN-marketplace.md §2.2, MP2).
 *
 * The shape that governs everything here: a product is DESCRIBED once by the
 * merchant and STOCKED per branch. So `products` write definitions, and
 * `listings` write what one shop charges and holds.
 *
 * The edit split (owner decision 2026-08-18): price, stock and availability
 * apply on the spot; name, description and artwork queue for review on a
 * store that is already selling. Enforced here, fail-closed — a key we do
 * not recognise is treated as a claim, never waved through.
 */
final class ProductController extends Controller
{
    public function __construct(private readonly ChangeRequestService $changes) {}

    /**
     * BOTH lists a product form needs, kept apart because they answer
     * different questions (owner decision 2026-08-19).
     *
     * `marketplace` is the shopper's vocabulary — global, shared by every
     * store, and the only thing that can make cross-shop browse work.
     * `cashback` is this merchant's own pricing, and it is OPTIONAL: a
     * product left unfiled earns the standing rate, exactly as it would
     * in-store. The mode and rate ride along so the form can show what
     * filing something will actually pay.
     */
    public function categories(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        return new JsonResponse([
            'data' => [
                'marketplace' => MarketplaceCategory::query()
                    ->where('active', true)
                    ->orderBy('sort')
                    ->get(['id', 'slug', 'name_en', 'name_dv', 'icon']),

                'cashback' => MerchantProductCategory::query()
                    ->where('merchant_id', $merchant->getKey())
                    ->where('active', true)
                    ->orderBy('sort')
                    ->get(['id', 'slug', 'name_en', 'name_dv', 'mode', 'rate_bp'])
                    ->map(fn (MerchantProductCategory $category): array => [
                        'id' => $category->id,
                        'slug' => $category->slug,
                        'name_en' => $category->name_en,
                        'name_dv' => $category->name_dv,
                        'mode' => $category->mode,
                        // Percent on the wire, never basis points (§1).
                        'rate_percent' => $category->rate_bp === null
                            ? null
                            : Percent::format((int) $category->rate_bp),
                    ])->values(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        $products = $merchant->products()
            ->with([
                'images', 'listings',
                'marketplaceCategory:id,slug,name_en,name_dv',
                'cashbackCategory:id,slug,name_en,name_dv,mode,rate_bp',
            ])
            ->when(
                $request->boolean('archived') === false,
                fn ($query) => $query->where('archived', false),
            )
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return new JsonResponse([
            'data' => $products->map($this->present(...))->values(),
            'meta' => [
                'pending_changes' => $this->changes
                    ->pendingFor($merchant)
                    ->filter(fn ($row): bool => $row->kind->isProduct())
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        $validated = $request->validate($this->definitionRules($merchant, null));

        // A NEW product is not a change to anything the public has seen, so
        // it never queues — it simply does not exist for shoppers until a
        // branch lists it as active.
        $product = $merchant->products()->create($validated);

        return new JsonResponse(['data' => $this->present($product->load('images', 'listings'))], 201);
    }

    /**
     * Edit a definition. On a store already selling this QUEUES; before that
     * it writes straight through, because there is no public claim to
     * protect yet.
     */
    public function update(Request $request, int $product): JsonResponse
    {
        $merchant = $this->merchant($request);
        $row = $merchant->products()->whereKey($product)->firstOrFail();

        $validated = $request->validate($this->definitionRules($merchant, $row->id));

        // The gate asks about THIS PRODUCT, not merely this store.
        //
        // A product no branch lists as active has never been in front of a
        // shopper, so its name is not yet a public claim and there is
        // nothing for a reviewer to protect. Gating it anyway would mean a
        // vendor loading a 124-line catalogue queues 124 review requests
        // for typos in products nobody can buy — which teaches everyone to
        // rubber-stamp the queue, and the queue only works if it is read.
        //
        // Once a shelf carries it, every word about it is gated.
        if (! ChangeRequestService::gates($merchant) || ! $this->isPublic($row)) {
            $row->fill($validated)->save();

            return new JsonResponse(['data' => $this->present($row->fresh()->load('images', 'listings'))]);
        }

        /** @var MerchantUser $actor */
        $actor = $request->user('merchant');

        $queued = $this->changes->submitProductChange($merchant, $actor, $row, $validated);

        if ($queued === null) {
            // Nothing actually moved. The panels PATCH the whole form, so a
            // re-save of an untouched product must not park a request.
            return new JsonResponse(['data' => $this->present($row->load('images', 'listings'))]);
        }

        return new JsonResponse(['data' => ['change_request' => $queued]], 202);
    }

    /**
     * Archive. Never a hard delete: an order from last month names this
     * product, and history must not develop holes.
     */
    public function archive(Request $request, int $product): JsonResponse
    {
        $merchant = $this->merchant($request);
        $row = $merchant->products()->whereKey($product)->firstOrFail();

        $row->forceFill(['archived' => true])->save();
        // Archiving pulls it off every shelf at once — a shopper must not be
        // able to buy something the merchant has retired.
        $row->listings()->update(['state' => 'archived']);

        return new JsonResponse(['data' => $this->present($row->fresh()->load('images', 'listings'))]);
    }

    /**
     * What ONE shop charges and holds. Instant, always — this is the
     * operational half, and it is why a shop can react to its own shelves.
     */
    public function listing(Request $request, int $product): JsonResponse
    {
        $merchant = $this->merchant($request);
        $row = $merchant->products()->whereKey($product)->firstOrFail();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('merchant_branches', 'id')->where('merchant_id', $merchant->getKey())],
            'price_laari' => ['required', 'integer', 'min:0', 'max:100000000'],
            // Only meaningful ABOVE the live price: a "was" that is not more
            // than the "now" is not a discount, it is a lie with a line
            // through it.
            'compare_at_laari' => ['nullable', 'integer', 'min:0', 'max:100000000', 'gt:price_laari'],
            // Null means this shop does not count stock for this line; zero
            // means counted, and there is none.
            'stock_qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'low_stock_at' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'state' => ['required', Rule::in(['draft', 'active', 'out_of_stock', 'archived'])],
        ]);

        $listing = BranchProduct::query()->updateOrCreate(
            ['branch_id' => $validated['branch_id'], 'product_id' => $row->id],
            $validated,
        );

        return new JsonResponse(['data' => $this->presentListing($listing)], 200);
    }

    public function uploadImage(Request $request, int $product): JsonResponse
    {
        $merchant = $this->merchant($request);
        $row = $merchant->products()->whereKey($product)->firstOrFail();

        $request->validate([
            'image' => ['required', 'file', 'extensions:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $file = $request->file('image');

        $path = $file->storeAs(
            sprintf('products/%d', $merchant->getKey()),
            Str::uuid().'.'.$file->getClientOriginalExtension(),
            ProductImage::DISK,
        );

        $image = $row->images()->create([
            'path' => $path,
            'sort' => (int) ($row->images()->max('sort') ?? 0) + 10,
        ]);

        return new JsonResponse(['data' => [
            'id' => $image->id,
            'url' => Storage::disk(ProductImage::DISK)->url($path),
            'sort' => $image->sort,
        ]], 201);
    }

    public function destroyImage(Request $request, int $product, int $image): JsonResponse
    {
        $merchant = $this->merchant($request);
        $row = $merchant->products()->whereKey($product)->firstOrFail();
        $picture = $row->images()->whereKey($image)->firstOrFail();

        Storage::disk(ProductImage::DISK)->delete($picture->path);
        $picture->delete();

        return new JsonResponse(null, 204);
    }

    /** Is any shop actually offering this right now? */
    private function isPublic(Product $product): bool
    {
        return $product->listings()->where('state', 'active')->exists();
    }

    /**
     * The GATED half — everything a shopper reads and judges the product by.
     *
     * @return array<string, mixed>
     */
    private function definitionRules(Merchant $merchant, ?int $ignore): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sku' => [
                'sometimes', 'nullable', 'string', 'max:64',
                Rule::unique('products', 'sku')
                    ->where('merchant_id', $merchant->getKey())
                    ->ignore($ignore),
            ],
            // Where it sits on the shelf. Shared across every store.
            'marketplace_category_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('marketplace_categories', 'id')->where('active', true),
            ],
            // What it earns. OPTIONAL — unset means the default
            // "everything else" bucket at the standing rate.
            //
            // Scoped to THIS merchant's own list. Without the merchant_id
            // clause a shop could file a product under another shop's
            // category and inherit its rate — a rate is money, and money
            // must not be reachable by guessing an id.
            'cashback_category_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('merchant_product_categories', 'id')
                    ->where('merchant_id', $merchant->getKey())
                    ->where('active', true),
            ],
            'cashback_rate_bp' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000'],
            'allow_substitutions' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'name_dv' => $product->name_dv,
            'description' => $product->description,
            'sku' => $product->sku,
            // The shelf label, shared across stores.
            'marketplace_category' => $product->marketplaceCategory === null ? null : [
                'id' => $product->marketplaceCategory->id,
                'slug' => $product->marketplaceCategory->slug,
                'name_en' => $product->marketplaceCategory->name_en,
                'name_dv' => $product->marketplaceCategory->name_dv,
            ],
            // What it earns, by this merchant's own list. Null is the
            // default "everything else" bucket at the standing rate.
            'cashback_category' => $product->cashbackCategory === null ? null : [
                'id' => $product->cashbackCategory->id,
                'slug' => $product->cashbackCategory->slug,
                'name_en' => $product->cashbackCategory->name_en,
                'name_dv' => $product->cashbackCategory->name_dv,
                'mode' => $product->cashbackCategory->mode,
                'rate_percent' => $product->cashbackCategory->rate_bp === null
                    ? null
                    : Percent::format((int) $product->cashbackCategory->rate_bp),
            ],
            'cashback_rate_percent' => $product->cashback_rate_bp === null
                ? null
                : Percent::format($product->cashback_rate_bp),
            'allow_substitutions' => $product->allow_substitutions,
            'archived' => $product->archived,
            'images' => $product->images->map(fn (ProductImage $image): array => [
                'id' => $image->id,
                'url' => Storage::disk(ProductImage::DISK)->url($image->path),
                'sort' => $image->sort,
            ])->values(),
            'listings' => $product->listings->map($this->presentListing(...))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentListing(BranchProduct $listing): array
    {
        return [
            'id' => $listing->id,
            'branch_id' => $listing->branch_id,
            'price_laari' => $listing->price_laari,
            'compare_at_laari' => $listing->compare_at_laari,
            'stock_qty' => $listing->stock_qty,
            'low_stock_at' => $listing->low_stock_at,
            'state' => $listing->state,
            'buyable' => $listing->isBuyable(),
            'low_stock' => $listing->isLowStock(),
        ];
    }

    private function merchant(Request $request): Merchant
    {
        $user = $request->user('merchant');
        abort_unless($user instanceof MerchantUser, 403);

        $merchant = $user->merchant;
        abort_if($merchant === null, 403);

        return $merchant;
    }
}
