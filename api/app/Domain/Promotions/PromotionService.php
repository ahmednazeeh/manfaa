<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Domain\Money\Rate;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The promotion lifecycle (PLAN §12 Phase 3, original spec §9): a merchant
 * drafts a temporary boosted rate with a window, a minimum purchase, an
 * optional per-customer cap and an optional branch scope; publishing makes
 * it live for the whole stated window.
 *
 * PLAN §7 immutability, enforced HERE in the domain and not in any UI:
 * once published a promotion cannot be edited, cannot be cancelled, and
 * cannot be ended early — the domain simply offers no such operation, and
 * publish/cancelDraft refuse anything that is not a draft. The published
 * window is a public commitment tills and customers rely on. Publishing
 * additionally requires starts_at >= now, so the frozen window always lies
 * entirely in the future — never retroactively repricing past sales.
 *
 * A promotion is a BOOST: its rate must strictly exceed the merchant's
 * standing rate effective at starts_at. Decreases have their own §7 path
 * (rate change effective at next business midnight); a promotion below the
 * standing rate would be a stealth decrease that bypasses that protection.
 * The boost rule is checked at draft time for early feedback and again,
 * authoritatively, at publish — the standing history may have moved in
 * between.
 *
 * The platform fee is not stored on the promotion: it follows the promo
 * rate's §4 tier automatically wherever the rate is applied
 * (CashbackCalculator resolves fee_bp from rate_bp at credit time).
 */
final readonly class PromotionService
{
    public function createDraft(
        Merchant $merchant,
        MerchantUser $creator,
        int $rateBp,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $minPurchaseLaari = null,
        ?int $maxCashbackPerCustomerLaari = null,
        ?int $branchId = null,
    ): Promotion {
        // §4: integer basis points 50–1000 or no fee tier exists.
        Rate::cashback($rateBp);

        $startsAt = $startsAt->utc();
        $endsAt = $endsAt->utc();

        if (! $endsAt->isAfter($startsAt)) {
            throw InvalidPromotionWindowException::endsBeforeStarts($startsAt, $endsAt);
        }

        if ($minPurchaseLaari !== null && $minPurchaseLaari < 0) {
            throw new InvalidArgumentException('Minimum purchase must not be negative.');
        }

        if ($maxCashbackPerCustomerLaari !== null && $maxCashbackPerCustomerLaari < 1) {
            throw new InvalidArgumentException('Per-customer cashback cap must be at least 1 laari.');
        }

        if ($branchId !== null && ! MerchantBranch::query()->whereKey($branchId)->where('merchant_id', $merchant->id)->exists()) {
            throw BranchNotOwnedException::for($merchant, $branchId);
        }

        $this->assertBoost($merchant, $rateBp, $startsAt);

        return Promotion::query()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branchId,
            'rate_bp' => $rateBp,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'min_purchase_laari' => $minPurchaseLaari,
            'max_cashback_per_customer_laari' => $maxCashbackPerCustomerLaari,
            'status' => 'draft',
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Draft → published, atomically under a row lock. From this moment the
     * promotion is immutable for its stated duration (PLAN §7): no edit, no
     * cancel, no early end exists anywhere in the domain.
     */
    public function publish(Promotion $promotion): Promotion
    {
        return DB::transaction(function () use ($promotion): Promotion {
            /** @var Promotion $locked */
            $locked = Promotion::query()->whereKey($promotion->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw PromotionNotDraftException::for($locked, 'publish');
            }

            $now = CarbonImmutable::now('UTC');

            if ($locked->starts_at->isBefore($now)) {
                throw InvalidPromotionWindowException::startsInPast($locked->starts_at, $now);
            }

            // Authoritative boost check against the standing rate that will
            // actually be in force when the window opens.
            $this->assertBoost($locked->merchant, $locked->rate_bp, $locked->starts_at);

            $locked->update(['status' => 'published', 'published_at' => $now]);

            return $locked;
        });
    }

    /**
     * Draft → cancelled. Only drafts: a published promotion cannot be
     * cancelled — that would be the forbidden early end.
     */
    public function cancelDraft(Promotion $promotion): Promotion
    {
        return DB::transaction(function () use ($promotion): Promotion {
            /** @var Promotion $locked */
            $locked = Promotion::query()->whereKey($promotion->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw PromotionNotDraftException::for($locked, 'cancel');
            }

            $locked->update(['status' => 'cancelled', 'cancelled_at' => CarbonImmutable::now('UTC')]);

            return $locked;
        });
    }

    /**
     * §5 standing-rate resolution at an instant, from the append-only
     * merchant_rates history — the same read CreditRecorder does at sale
     * time, here evaluated at the promotion's starts_at (which may be in
     * the future, so scheduled rate rows participate correctly).
     */
    public function standingRateBpAt(Merchant $merchant, CarbonImmutable $at): ?int
    {
        $rate = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '<=', $at)
            ->where(function ($query) use ($at) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $at);
            })
            ->orderByDesc('effective_from')
            ->first();

        return $rate?->rate_bp;
    }

    private function assertBoost(Merchant $merchant, int $rateBp, CarbonImmutable $startsAt): void
    {
        $standingBp = $this->standingRateBpAt($merchant, $startsAt)
            ?? throw NoStandingRateException::for($merchant, $startsAt);

        if ($rateBp <= $standingBp) {
            throw PromotionRateNotBoostException::against($rateBp, $standingBp);
        }
    }
}
