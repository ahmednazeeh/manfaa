<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Platform\FeePromotionPolicy;
use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * THE BANNER THIS STORE SHOULD BE SHOWN (owner, 2026-08-25): "Show promos
 * when enabled on merchant panel and app."
 *
 * One endpoint, mounted on BOTH merchant doors — the web panel and the till
 * app — because it is one sentence and there is no reason for two versions
 * of it to drift apart.
 *
 * NO PERMISSION GATE. Every account that may log in to a store may be told
 * what that store is being charged; withholding it from a cashier would only
 * mean the shop finds out from an invoice. It is the store's own promotion,
 * scoped to the authenticated merchant by construction.
 *
 * WHAT IT ANSWERS. The winning offer for THIS merchant right now — for an
 * introductory promotion that is their own window, computed from their own
 * `approved_at`, so `ends_at` and `days_remaining` are personal to them.
 * That is exactly what the PUBLIC landing endpoint must never say, and why
 * the two are separate routes rather than one shape with a flag.
 *
 * THE SHAPE IS STABLE. `active: false` still carries every key, as null — a
 * client must not have to guess which fields it is allowed to expect.
 */
final class FeePromotionBannerController extends Controller
{
    public function __construct(private readonly FeePromotionPolicy $promotions) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        $now = CarbonImmutable::now('UTC');
        $offer = $this->promotions->offerFor((int) $user->merchant_id, $now);

        return new JsonResponse([
            'data' => [
                'active' => $offer !== null,
                ...($offer?->toMerchantArray($now) ?? [
                    'kind' => null,
                    'kind_label' => null,
                    'platform_fee_percent' => null,
                    'ends_at' => null,
                    'days_remaining' => null,
                    'banner_en' => null,
                    'banner_dv' => null,
                ]),
            ],
        ]);
    }
}
