<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sale-time promotion resolution (original spec §9, PLAN §12 Phase 3).
 * Only PUBLISHED promotions whose window covers the instant count — drafts
 * and cancelled drafts never price anything, and a published window needs
 * no "ended" sweep because resolution is purely temporal: past ends_at the
 * promotion simply stops matching.
 *
 * When windows overlap, the highest rate wins (customer-favourable, and the
 * only ordering a merchant can reason about), tie-broken by the later
 * starts_at, then id — deterministic under every fixture.
 */
final class PromotionResolver
{
    /**
     * The promotions that could price a sale, best first: merchant's own,
     * published, window covering occurred_at (starts_at <= t < ends_at),
     * branch scope matching (a NULL-branch promotion covers every branch; a
     * branch-scoped one only its own), and the sale meeting the minimum
     * purchase. A sale below every minimum purchase simply resolves NO
     * promotion — it falls back to the standing rate, it is never rejected.
     *
     * ALL matching candidates are returned in resolution order because the
     * best one may be exhausted for this customer (per-customer cap): the
     * caller walks the list and the next live promotion still prices the
     * sale rather than dropping all the way to the standing rate.
     *
     * @return Collection<int, Promotion>
     */
    public function candidatesAt(int $merchantId, ?int $branchId, int $eligibleLaari, CarbonImmutable $at): Collection
    {
        return $this->liveQuery($merchantId, $at)
            ->where(function (Builder $query) use ($branchId) {
                $query->whereNull('branch_id');

                if ($branchId !== null) {
                    $query->orWhere('branch_id', $branchId);
                }
            })
            ->where(function (Builder $query) use ($eligibleLaari) {
                $query->whereNull('min_purchase_laari')
                    ->orWhere('min_purchase_laari', '<=', $eligibleLaari);
            })
            ->get();
    }

    /**
     * The live promotion for the till display (§9.2 rate endpoint): no
     * branch or amount filter — the endpoint has no branch context, so the
     * caller surfaces branch_id and min_purchase_laari for the till to
     * apply. Advisory only; the server always re-resolves at occurred_at.
     */
    public function liveForMerchant(int $merchantId, CarbonImmutable $at): ?Promotion
    {
        return $this->liveQuery($merchantId, $at)->first();
    }

    /**
     * @return Builder<Promotion>
     */
    private function liveQuery(int $merchantId, CarbonImmutable $at): Builder
    {
        return Promotion::query()
            ->where('merchant_id', $merchantId)
            ->where('status', 'published')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->orderByDesc('rate_bp')
            ->orderByDesc('starts_at')
            ->orderBy('id');
    }
}
