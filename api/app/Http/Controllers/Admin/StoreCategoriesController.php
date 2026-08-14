<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\StoreCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $category = StoreCategory::query()->create([
            'slug' => $validated['slug'],
            'name_en' => $validated['name_en'],
            'name_dv' => $validated['name_dv'] ?? null,
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
     * @return array<string, mixed>
     */
    private function present(StoreCategory $category, int $activeMerchantCount): array
    {
        return [
            'id' => $category->id,
            'slug' => $category->slug,
            'name_en' => $category->name_en,
            'name_dv' => $category->name_dv,
            'sort' => $category->sort,
            'active' => $category->active,
            'active_merchant_count' => $activeMerchantCount,
        ];
    }
}
