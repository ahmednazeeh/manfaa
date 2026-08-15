<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Money\Percent;
use App\Domain\Platform\FeeTierScheduleResolver;
use App\Domain\Promotions\PromotionResolver;
use App\Models\Merchant;
use App\Models\MerchantProductCategory;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/merchants/me/rate — the till's advisory rate display (§9.2). Every
 * rate here is a 2-decimal percent STRING (PLAN §1 wire format:
 * `cashback_rate_percent` / `platform_fee_percent`); basis points are the
 * platform's internal representation and never reach a vendor. The
 * server always recomputes authoritatively at occurred_at; what the till
 * caches can only ever under-promise, because rate DECREASES take effect at
 * 00:00 next day (business timezone) and are surfaced here ahead of time as
 * pending_decrease. Increases apply immediately and never appear pending.
 *
 * A live published promotion (PLAN §12 Phase 3) is surfaced as
 * active_promotion — present ONLY while one is live and genuinely boosting
 * (its rate above the current standing rate), so the base contract stays
 * byte-stable for vendors that predate promotions. Branch-scoped
 * promotions carry their branch_id; the till applies min_purchase_laari
 * and branch scope itself for display, and the server still re-resolves
 * authoritatively at occurred_at.
 *
 * has_category_overrides is the honesty flag on the headline rate. A store
 * that excludes tobacco, or prices vegetables at 1%, still has ONE standing
 * rate — and a till that shows only that rate quotes a number the basket
 * will not earn. The flag says "this store's rate depends on what is in the
 * basket"; the till then reads
 * GET /v1/merchants/me/product-categories and submits lines[]. It is a
 * boolean rather than the category list itself so this endpoint stays the
 * cheap, cacheable poll it is meant to be.
 */
class MerchantRateController extends V1Controller
{
    public function __invoke(Request $request, PromotionResolver $promotions, FeeTierScheduleResolver $feeTiers): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $now = CarbonImmutable::now('UTC');

        $current = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $now);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($current === null) {
            return $this->error(422, 'no_effective_rate', 'No cashback rate is currently effective — contact the platform.');
        }

        $pending = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '>', $now)
            ->orderBy('effective_from')
            ->first();

        $pendingDecrease = null;

        // Every fee below resolves from the admin-managed schedule — the
        // same source billing (TermsResolver) prices from — at the instant
        // each rate is or becomes effective. The static §4 map would quote a
        // stale fee the moment a published schedule diverges.
        if ($pending !== null && $pending->rate_bp < $current->rate_bp) {
            $pendingDecrease = [
                'cashback_rate_percent' => Percent::format($pending->rate_bp),
                'platform_fee_percent' => Percent::format(
                    $feeTiers->feeBpAt($pending->rate_bp, $pending->effective_from->utc()),
                ),
                'effective_at' => $pending->effective_from
                    ->setTimezone((string) config('app.business_timezone', 'Indian/Maldives'))
                    ->toIso8601String(),
            ];
        }

        $response = [
            'cashback_rate_percent' => Percent::format($current->rate_bp),
            'platform_fee_percent' => Percent::format($feeTiers->feeBpAt($current->rate_bp, $now)),
            'currency' => 'MVR',
            'min_eligible_laari' => $merchant->min_eligible_laari,
            // The headline rate above is NOT the whole story for this store
            // when true: some products are excluded or carry their own
            // rate, so a till that prints "cashback_rate_percent" against
            // the whole basket will over-promise. Fetch
            // GET /v1/merchants/me/product-categories and send lines[].
            'has_category_overrides' => MerchantProductCategory::query()
                ->where('merchant_id', $merchant->id)
                ->where('active', true)
                ->exists(),
            'pending_decrease' => $pendingDecrease,
        ];

        $promotion = $promotions->liveForMerchant($merchant->id, $now);

        if ($promotion !== null && $promotion->rate_bp > $current->rate_bp) {
            $response['active_promotion'] = [
                'cashback_rate_percent' => Percent::format($promotion->rate_bp),
                'platform_fee_percent' => Percent::format($feeTiers->feeBpAt($promotion->rate_bp, $now)),
                'branch_id' => $promotion->branch_id,
                'min_purchase_laari' => (int) ($promotion->min_purchase_laari ?? 0),
                'ends_at' => $promotion->ends_at
                    ->setTimezone((string) config('app.business_timezone', 'Indian/Maldives'))
                    ->toIso8601String(),
            ];
        }

        return new JsonResponse($response);
    }
}
