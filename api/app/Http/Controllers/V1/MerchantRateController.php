<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Money\FeeTier;
use App\Domain\Promotions\PromotionResolver;
use App\Models\Merchant;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/merchants/me/rate — the till's advisory rate display (§9.2). The
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
 */
class MerchantRateController extends V1Controller
{
    public function __invoke(Request $request, PromotionResolver $promotions): JsonResponse
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

        if ($pending !== null && $pending->rate_bp < $current->rate_bp) {
            $pendingDecrease = [
                'rate_bp' => $pending->rate_bp,
                'fee_bp' => FeeTier::feeBpFor($pending->rate_bp),
                'effective_at' => $pending->effective_from
                    ->setTimezone((string) config('app.business_timezone', 'Indian/Maldives'))
                    ->toIso8601String(),
            ];
        }

        $response = [
            'rate_bp' => $current->rate_bp,
            'fee_bp' => FeeTier::feeBpFor($current->rate_bp),
            'currency' => 'MVR',
            'min_eligible_laari' => $merchant->min_eligible_laari,
            'pending_decrease' => $pendingDecrease,
        ];

        $promotion = $promotions->liveForMerchant($merchant->id, $now);

        if ($promotion !== null && $promotion->rate_bp > $current->rate_bp) {
            $response['active_promotion'] = [
                'rate_bp' => $promotion->rate_bp,
                'fee_bp' => FeeTier::feeBpFor($promotion->rate_bp),
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
