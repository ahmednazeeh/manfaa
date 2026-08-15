<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Money\Percent;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/merchants/me/product-categories (ability rates:read): the ACTIVE
 * product categories a vendor may reference in POST /v1/transactions
 * lines[].category. `category` is the exact value to submit; anything not
 * listed here (or a deactivated slug) is refused at credit time with
 * `unknown_category` / `inactive_category`. Amounts in no listed category
 * belong on the default line (`category: null`), priced at the standing
 * rate.
 */
class ProductCategoriesController extends V1Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $categories = MerchantProductCategory::query()
            ->where('merchant_id', $merchant->id)
            ->where('active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['slug', 'name_en', 'name_dv', 'mode', 'rate_bp']);

        return new JsonResponse([
            'data' => $categories->map(fn (MerchantProductCategory $category): array => [
                'category' => $category->slug,
                'name_en' => $category->name_en,
                'name_dv' => $category->name_dv,
                'mode' => $category->mode,
                // 2-decimal percent string (PLAN §1 wire format), null for
                // an excluded category.
                'cashback_rate_percent' => Percent::formatOrNull($category->rate_bp),
            ])->all(),
        ]);
    }
}
