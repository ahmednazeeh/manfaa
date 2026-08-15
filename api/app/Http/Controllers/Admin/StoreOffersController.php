<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Storefront\OfferImage;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\StoreOffer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD over the curated featured offers — the image banners at the
 * top of Discover.
 *
 * The admin supplies artwork, words and a schedule. Everything else on the
 * rendered banner (the store's logo, its live cashback rate, its category)
 * is read from the merchant at render time and is deliberately NOT stored
 * here: a promotional surface whose figures can drift from the shop it
 * advertises is worse than no promotional surface.
 *
 * There is no DELETE. Deactivation retires an offer while leaving the
 * campaign on the record, which is the same posture as store categories —
 * and an offer is cheap to leave lying around, unlike a merchant row.
 */
class StoreOffersController extends Controller
{
    public function index(): JsonResponse
    {
        $offers = StoreOffer::query()
            ->with('merchant:id,name,name_dv,slug,status')
            ->orderBy('sort')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $offers->map($this->present(...))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, creating: true);

        $offer = StoreOffer::query()->create($validated);

        $this->forgetStorefront();

        // refresh(): `active` and `sort` come from column defaults when the
        // request omits them, and the created instance does not carry them
        // until it is re-read — so the response would otherwise report an
        // offer as inactive that the database has stored as live.
        return response()->json(
            ['data' => $this->present($offer->refresh()->load('merchant:id,name,name_dv,slug,status'))],
            201,
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $offer = StoreOffer::query()->findOrFail($id);

        $offer->fill($this->validated($request, creating: false))->save();

        $this->forgetStorefront();

        return response()->json([
            'data' => $this->present($offer->refresh()->load('merchant:id,name,name_dv,slug,status')),
        ]);
    }

    /**
     * Replaces the banner artwork. Multipart, so it cannot ride the JSON
     * save; the previous file is deleted only AFTER the replacement is
     * safely on disk.
     */
    public function uploadImage(Request $request, int $id): JsonResponse
    {
        $offer = StoreOffer::query()->findOrFail($id);

        $request->validate([
            'image' => [
                'required', 'file', 'image',
                // Raster only — see OfferImage on why SVG is refused.
                'mimes:jpg,jpeg,png,webp',
                'max:'.OfferImage::MAX_KB,
                // A banner is a wide card. The floor is what stays sharp on
                // a 2x phone at full width; the ceiling stops a print-sized
                // photograph becoming the heaviest asset on the page.
                Rule::dimensions()->minWidth(600)->minHeight(200)->maxWidth(4000)->maxHeight(2500),
            ],
        ]);

        $file = $request->file('image');
        $extension = strtolower($file->extension());

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $previous = $offer->image_path;

        $path = $file->storeAs(
            'offers/'.$offer->id,
            Str::uuid()->toString().'.'.$extension,
            OfferImage::DISK,
        );

        abort_if($path === false, 500, 'Could not store the image.');

        if ($previous !== null && $previous !== '' && $previous !== $path) {
            Storage::disk(OfferImage::DISK)->delete($previous);
        }

        $offer->image_path = $path;
        $offer->save();

        $this->forgetStorefront();

        return response()->json([
            'data' => $this->present($offer->load('merchant:id,name,name_dv,slug,status')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'merchant_id' => [
                $creating ? 'required' : 'sometimes',
                'integer',
                Rule::exists('merchants', 'id'),
            ],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'title_dv' => ['sometimes', 'nullable', 'string', 'max:120'],
            'blurb' => ['sometimes', 'nullable', 'string', 'max:240'],
            'blurb_dv' => ['sometimes', 'nullable', 'string', 'max:240'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:40'],
            'badge_dv' => ['sometimes', 'nullable', 'string', 'max:40'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            // An offer that ends before it starts would never render and
            // would look like a broken save rather than an impossible one.
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
        ];

        return $request->validate($rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StoreOffer $offer): array
    {
        $now = CarbonImmutable::now('UTC');
        $merchant = $offer->merchant;

        return [
            'id' => $offer->id,
            'merchant_id' => $offer->merchant_id,
            'merchant' => $merchant === null ? null : [
                'name' => $merchant->name,
                'name_dv' => $merchant->name_dv,
                'slug' => $merchant->slug,
                'status' => $merchant->status,
            ],
            'title' => $offer->title,
            'title_dv' => $offer->title_dv,
            'blurb' => $offer->blurb,
            'blurb_dv' => $offer->blurb_dv,
            'badge' => $offer->badge,
            'badge_dv' => $offer->badge_dv,
            'image_url' => OfferImage::url($offer->id, $offer->image_path),
            'starts_at' => $offer->starts_at?->toIso8601String(),
            'ends_at' => $offer->ends_at?->toIso8601String(),
            'sort' => $offer->sort,
            'active' => $offer->active,
            /**
             * Why this offer is or is not on the storefront right now — the
             * question an admin actually has when a banner they just saved
             * is nowhere to be seen. Computed, never stored.
             */
            'live' => $this->liveReason($offer, $now),
        ];
    }

    private function liveReason(StoreOffer $offer, CarbonImmutable $now): string
    {
        if (! $offer->active) {
            return 'inactive';
        }

        if ($offer->image_path === null || $offer->image_path === '') {
            return 'no_image';
        }

        if ($offer->starts_at !== null && $offer->starts_at->isAfter($now)) {
            return 'scheduled';
        }

        if ($offer->ends_at !== null && $offer->ends_at->isBefore($now)) {
            return 'ended';
        }

        // The merchant gate is the storefront's, not this table's: an offer
        // for a store that is not trading is hidden by the same rule that
        // hides the store.
        if ($offer->merchant?->status !== 'active') {
            return 'store_not_trading';
        }

        return 'live';
    }

    /** Offers ride the 60-second discovery read model. */
    private function forgetStorefront(): void
    {
        Cache::forget(DiscoveryService::CACHE_KEY);
    }
}
