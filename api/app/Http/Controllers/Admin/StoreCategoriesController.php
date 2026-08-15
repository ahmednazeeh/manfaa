<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Storefront\StoreCategoryIcon;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\StoreCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD over the curated store categories (§1 decision 2026-08-15).
 * There is no DELETE — deactivation is the only removal, and it is blocked
 * (409 category_in_use) while any ACTIVE merchant still carries the slug,
 * so a live storefront can never point at a vanished category. The slug is
 * immutable after creation; merchants store it by value.
 */
class StoreCategoriesController extends Controller
{
    public function index(): JsonResponse
    {
        $inUse = Merchant::query()
            ->where('status', 'active')
            ->whereNotNull('category')
            ->selectRaw('category, COUNT(*) AS n')
            ->groupBy('category')
            ->pluck('n', 'category');

        $categories = StoreCategory::query()
            ->orderBy('sort')
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (StoreCategory $category): array => $this->present($category, (int) ($inUse[$category->slug] ?? 0)))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => [
                'required', 'string', 'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('store_categories', 'slug'),
            ],
            'name_en' => ['required', 'string', 'max:120'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:120'],
            'icon' => ['sometimes', 'nullable', 'string', Rule::in(StoreCategory::ICONS)],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $category = StoreCategory::query()->create([
            'slug' => $validated['slug'],
            'name_en' => $validated['name_en'],
            'name_dv' => $validated['name_dv'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'sort' => $validated['sort'] ?? 0,
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json(['data' => $this->present($category, 0)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = StoreCategory::query()->findOrFail($id);

        $validated = $request->validate([
            'name_en' => ['sometimes', 'string', 'max:120'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:120'],
            'icon' => ['sometimes', 'nullable', 'string', Rule::in(StoreCategory::ICONS)],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $activeMerchants = Merchant::query()
            ->where('status', 'active')
            ->where('category', $category->slug)
            ->count();

        // Deactivating a category that live storefronts still display would
        // strand their listing filter — refuse until they are re-homed.
        if (($validated['active'] ?? true) === false && $category->active && $activeMerchants > 0) {
            return response()->json([
                'message' => sprintf(
                    'Category %s is in use by %d active store(s) and cannot be deactivated.',
                    $category->slug,
                    $activeMerchants,
                ),
                'code' => 'category_in_use',
            ], 409);
        }

        $category->fill($validated)->save();

        return response()->json(['data' => $this->present($category->refresh(), $activeMerchants)]);
    }

    /**
     * Replaces the category's icon artwork. Multipart, so it cannot ride the
     * JSON PATCH; the previous file is deleted only AFTER the replacement is
     * safely on disk, so a failed write never leaves the rail iconless.
     */
    public function uploadIcon(Request $request, int $id): JsonResponse
    {
        $category = StoreCategory::query()->findOrFail($id);

        $request->validate([
            'icon' => [
                'required', 'file', 'image',
                // Raster only — see StoreCategoryIcon on why SVG is refused.
                'mimes:jpg,jpeg,png,webp',
                'max:'.StoreCategoryIcon::MAX_KB,
                // 64px minimum keeps it crisp in the rail's tile on a 2x
                // display. Aspect ratio is deliberately unconstrained — the
                // tile centres and contains the artwork, so a non-square
                // upload is letterboxed rather than rejected.
                Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(2048)->maxHeight(2048),
            ],
        ]);

        $file = $request->file('icon');
        $extension = strtolower($file->extension());

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $previous = $category->icon_path;

        $path = $file->storeAs(
            'store-categories/'.$category->id,
            Str::uuid()->toString().'.'.$extension,
            StoreCategoryIcon::DISK,
        );

        abort_if($path === false, 500, 'Could not store the icon.');

        if ($previous !== null && $previous !== '' && $previous !== $path) {
            Storage::disk(StoreCategoryIcon::DISK)->delete($previous);
        }

        $category->icon_path = $path;
        $category->save();

        // The rail is served from the 60s discovery read model; without this
        // the new artwork would not appear for up to a minute.
        Cache::forget(DiscoveryService::CACHE_KEY);

        return response()->json(['data' => $this->present($category, $this->activeMerchantCount($category))]);
    }

    /**
     * Removes the uploaded artwork. The category then falls back to its
     * curated glyph name, which is why this is a clean removal rather than
     * something that can leave the rail blank.
     */
    public function destroyIcon(int $id): JsonResponse
    {
        $category = StoreCategory::query()->findOrFail($id);

        if ($category->icon_path !== null && $category->icon_path !== '') {
            Storage::disk(StoreCategoryIcon::DISK)->delete($category->icon_path);
        }

        $category->icon_path = null;
        $category->save();

        Cache::forget(DiscoveryService::CACHE_KEY);

        return response()->json(['data' => $this->present($category, $this->activeMerchantCount($category))]);
    }

    private function activeMerchantCount(StoreCategory $category): int
    {
        return Merchant::query()
            ->where('status', 'active')
            ->where('category', $category->slug)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StoreCategory $category, int $activeMerchantCount): array
    {
        return [
            'id' => $category->id,
            'slug' => $category->slug,
            'name_en' => $category->name_en,
            'name_dv' => $category->name_dv,
            'icon' => $category->icon,
            // Uploaded artwork wins in every client; `icon` above is the
            // curated glyph the rail falls back to.
            'icon_url' => StoreCategoryIcon::url($category->slug, $category->icon_path),
            'sort' => $category->sort,
            'active' => $category->active,
            'active_merchant_count' => $activeMerchantCount,
        ];
    }
}
