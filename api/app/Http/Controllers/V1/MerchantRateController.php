<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1;

use App\Domain\Money\Percent;
use App\Domain\Platform\FeePromotionPolicy;
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
 *
 * PLATFORM FEE PROMOTIONS (owner, 2026-08-25) are surfaced as
 * active_fee_promotion — present ONLY while one is in force for this store,
 * so the base contract stays byte-stable for vendors that predate the
 * feature. It matters here because every OTHER platform_fee_percent on this
 * response is the §4 TIER price for a rate, and since this feature that is a
 * LIST price rather than the billed one: TermsResolver charges
 * min(promotion, tier) per sale. A vendor till that prints a platform fee
 * therefore has to read this block, and the two fields say the two things it
 * needs — the CEILING the promotion puts on every fee below, and, so the
 * common case needs no arithmetic at all, what a sale at the headline
 * cashback_rate_percent is actually billed today.
 *
 * The first-party surfaces (panel and till app) learn the same fact from
 * GET /merchant/fee-promotion, which additionally carries the superadmin's
 * banner copy; this door is a machine contract, so it carries the numbers
 * and the campaign's own words, and leaves the display to the vendor.
 */
class MerchantRateController extends V1Controller
{
    public function __invoke(
        Request $request,
        PromotionResolver $promotions,
        FeeTierScheduleResolver $feeTiers,
        FeePromotionPolicy $feePromotions,
    ): JsonResponse {
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
        //
        // It is the §4 TIER fee, which since fee promotions (owner,
        // 2026-08-25) is the LIST price rather than always the billed one:
        // a sale is charged min(active_fee_promotion.platform_fee_percent,
        // the tier fee below). When no promotion is in force — every store
        // on the platform until a superadmin switches one on — the two are
        // the same number and this response reads exactly as it always did.
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

        // What this store is ACTUALLY billed today. Every platform_fee_percent
        // above is a §4 tier price; this block is the promotion that caps it,
        // and `effective_platform_fee_percent` is that cap already applied to
        // the headline rate — the figure a till prints beside a sale it is
        // ringing up right now.
        $feePromotion = $feePromotions->offerFor((int) $merchant->id, $now);

        if ($feePromotion !== null) {
            $relief = $feePromotion->relief();

            $response['active_fee_promotion'] = [
                'kind' => $feePromotion->kind->value,
                'kind_label' => $feePromotion->kind->label(),
                // The CEILING: no sale of this store's is billed above it,
                // whatever tier priced it.
                'platform_fee_percent' => Percent::format($feePromotion->feeBp),
                // The ceiling already applied to cashback_rate_percent's own
                // tier fee, so a till that only ever quotes the headline rate
                // needs no arithmetic of its own.
                'effective_platform_fee_percent' => Percent::format(
                    $relief->chargedFeeBp($feeTiers->feeBpAt($current->rate_bp, $now)),
                ),
                // EXCLUSIVE: the first instant the promotion stops pricing a
                // sale. A till showing a last date to a cashier should show
                // the day BEFORE this instant.
                'ends_at' => $feePromotion->endsAt
                    ?->setTimezone((string) config('app.business_timezone', 'Indian/Maldives'))
                    ->toIso8601String(),
                'days_remaining' => $feePromotion->daysRemaining($now),
                'banner_en' => $feePromotion->bannerEn,
                'banner_dv' => $feePromotion->bannerDv,
            ];
        }

        return new JsonResponse($response);
    }
}
