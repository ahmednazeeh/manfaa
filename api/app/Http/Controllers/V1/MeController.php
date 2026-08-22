<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Money\Percent;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `GET /v1/me` — who am I, and what may I do (owner, 2026-08-22).
 *
 * The one call a plugin's "Test connection" needs: the store the token
 * belongs to, the abilities it actually carries (so the plugin can warn
 * "refunds will not reverse cashback" when `transactions:reverse` is
 * missing, instead of discovering it on the first refund), where the grant
 * came from, and enough of the rate card to print a sentence.
 *
 * Needs no ability beyond a live credential: a token may always read what
 * it is. The rate summary here is a convenience — the authoritative,
 * cacheable rate card stays at `GET /v1/merchants/me/rate`.
 */
final class MeController extends V1Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        /** @var PersonalAccessToken|null $token */
        $token = $merchant->currentAccessToken();

        $credential = $token instanceof PersonalAccessToken
            ? ApiCredential::query()
                ->where('merchant_id', $merchant->getKey())
                ->where('personal_access_token_id', $token->getKey())
                ->with('posVendor')
                ->first()
            : null;

        $now = CarbonImmutable::now('UTC');

        $rate = MerchantRate::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->orderByDesc('effective_from')
            ->first();

        return new JsonResponse([
            'merchant' => [
                'id' => $merchant->getKey(),
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'status' => $merchant->status,
                'currency' => 'MVR',
            ],
            'credential' => [
                'id' => $credential?->getKey(),
                'label' => $credential?->posVendor?->display_name ?? $credential?->posVendor?->name ?? $credential?->label,
                // The abilities the TOKEN carries — what the ability gate
                // will actually allow, not what was asked for.
                'abilities' => array_values((array) ($token?->abilities ?? [])),
                // `https://shop.example.mv` for a public-client grant; null
                // for a panel-issued or admin-issued credential.
                'connected_from' => $credential?->connected_from,
                'issued_at' => $credential?->created_at?->toIso8601String(),
            ],
            'rate' => $rate === null ? null : [
                'cashback_rate_percent' => Percent::format($rate->rate_bp),
                'min_eligible_laari' => (int) $merchant->min_eligible_laari,
                'has_category_overrides' => MerchantProductCategory::query()
                    ->where('merchant_id', $merchant->getKey())
                    ->where('active', true)
                    ->exists(),
            ],
        ]);
    }
}
