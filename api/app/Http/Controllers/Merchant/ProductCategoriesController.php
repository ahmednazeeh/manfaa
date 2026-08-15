<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Platform\RateNotPricedException;
use App\Domain\Platform\TierScheduleService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCategoryResource;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Merchant CRUD for per-store product categories (Task #25).
 *
 * - GET is STAFF-readable — it feeds the credit form (staff key in manual
 *   credits); POST/PATCH are owner-only behind EnsureMerchantOwner +
 *   EnsureMerchantApproved (route-level split).
 * - The slug is generated from name_en at creation and is IMMUTABLE:
 *   renames never touch it (it is the public line key vendors integrate
 *   against, and transaction_lines snapshot it).
 * - Mode/rate changes affect FUTURE credits only — historical lines are
 *   frozen snapshots.
 * - Deactivation is soft (PATCH active: false); there is no delete.
 * - Rate overrides obey the same sellability law as the standing rate:
 *   50..2000 structurally, refused above the fee tier schedule ceiling
 *   with code `rate_not_priced`, checked under the coverage lock so a
 *   concurrently published narrower schedule cannot slip past.
 */
class ProductCategoriesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $categories = MerchantProductCategory::query()
            ->where('merchant_id', $this->merchant($request)->id)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return ProductCategoryResource::collection($categories)->response($request);
    }

    public function store(Request $request, TierScheduleService $schedules): JsonResponse
    {
        $validated = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_dv' => ['nullable', 'string', 'max:120'],
            'mode' => ['required', 'in:excluded,rate'],
            'rate_bp' => ['required_if:mode,rate', 'prohibited_if:mode,excluded', 'nullable', 'integer', 'min:50', 'max:2000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'slug' => ['prohibited'],
        ]);

        $merchant = $this->merchant($request);
        $rateBp = $validated['mode'] === 'rate' ? (int) $validated['rate_bp'] : null;

        try {
            $category = DB::transaction(function () use ($merchant, $validated, $rateBp, $schedules): MerchantProductCategory {
                if ($rateBp !== null) {
                    // Same coverage discipline as the standing-rate change:
                    // lock, then refuse a rate no current-or-future schedule
                    // prices — otherwise every future credit on this
                    // category would throw at billing time.
                    TierScheduleService::lockCoverage();
                    $schedules->assertPricedThrough($rateBp, now()->toImmutable()->utc());
                }

                return MerchantProductCategory::query()->create([
                    'merchant_id' => $merchant->id,
                    'slug' => $this->uniqueSlug($merchant, $validated['name_en']),
                    'name_en' => $validated['name_en'],
                    'name_dv' => $validated['name_dv'] ?? null,
                    'mode' => $validated['mode'],
                    'rate_bp' => $rateBp,
                    'active' => true,
                    'sort' => (int) ($validated['sort'] ?? 0),
                ]);
            });
        } catch (RateNotPricedException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => RateNotPricedException::CODE,
            ], 422);
        }

        return (new ProductCategoryResource($category))->response($request)->setStatusCode(201);
    }

    public function update(Request $request, int $id, TierScheduleService $schedules): JsonResponse
    {
        $merchant = $this->merchant($request);

        $category = MerchantProductCategory::query()
            ->where('merchant_id', $merchant->id)
            ->whereKey($id)
            ->first();

        if ($category === null) {
            abort(404, 'No such product category.');
        }

        $validated = $request->validate([
            'name_en' => ['sometimes', 'string', 'max:120'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mode' => ['sometimes', 'in:excluded,rate'],
            'rate_bp' => ['sometimes', 'nullable', 'integer', 'min:50', 'max:2000'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'active' => ['sometimes', 'boolean'],
            'slug' => ['prohibited'],
        ]);

        // Resolve the FINAL mode/rate pair and validate their coherence —
        // a rate override always carries a rate, an exclusion never does.
        $mode = $validated['mode'] ?? $category->mode;
        $rateBp = array_key_exists('rate_bp', $validated)
            ? ($validated['rate_bp'] === null ? null : (int) $validated['rate_bp'])
            : ($mode === 'rate' ? $category->rate_bp : null);

        if ($mode === 'rate' && $rateBp === null) {
            throw ValidationException::withMessages([
                'rate_bp' => ['A rate is required when mode is "rate".'],
            ]);
        }

        if ($mode === 'excluded' && $rateBp !== null) {
            throw ValidationException::withMessages([
                'rate_bp' => ['An excluded category cannot carry a rate.'],
            ]);
        }

        $changes = [
            'name_en' => $validated['name_en'] ?? $category->name_en,
            'name_dv' => array_key_exists('name_dv', $validated) ? $validated['name_dv'] : $category->name_dv,
            'mode' => $mode,
            'rate_bp' => $rateBp,
            'sort' => array_key_exists('sort', $validated) ? (int) $validated['sort'] : $category->sort,
            'active' => array_key_exists('active', $validated) ? (bool) $validated['active'] : $category->active,
            // The slug NEVER changes — renames keep the line key stable.
        ];

        try {
            DB::transaction(function () use ($category, $changes, $rateBp, $schedules): void {
                if ($rateBp !== null && $rateBp !== $category->rate_bp) {
                    TierScheduleService::lockCoverage();
                    $schedules->assertPricedThrough($rateBp, now()->toImmutable()->utc());
                }

                // Mode/rate changes affect FUTURE credits only — historical
                // transaction_lines keep the snapshots they priced under.
                $category->update($changes);
            });
        } catch (RateNotPricedException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => RateNotPricedException::CODE,
            ], 422);
        }

        return (new ProductCategoryResource($category->refresh()))->response($request);
    }

    /**
     * Slugifies name_en and makes it unique within the merchant by suffixing
     * -2, -3, … A name that slugifies to nothing (e.g. pure Thaana) falls
     * back to "category". Uniqueness is race-backed by the DB unique index.
     */
    private function uniqueSlug(Merchant $merchant, string $nameEn): string
    {
        $base = Str::slug($nameEn);

        if ($base === '') {
            $base = 'category';
        }

        $base = Str::limit($base, 70, '');

        $taken = MerchantProductCategory::query()
            ->where('merchant_id', $merchant->id)
            ->where('slug', 'like', $base.'%')
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        for ($i = 2; ; $i++) {
            $candidate = $base.'-'.$i;

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    private function merchant(Request $request): Merchant
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user->merchant;
    }
}
