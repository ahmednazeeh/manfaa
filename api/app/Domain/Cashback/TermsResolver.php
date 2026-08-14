<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\CashbackResult;
use App\Domain\Money\Laari;
use App\Domain\Money\Rate;
use App\Domain\Promotions\PromotionResolver;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The terms an accruing sale earns under — shared by every path that mints
 * a cashback row (live credits via CreditRecorder, claim approvals via
 * ClaimApprovalService), so a claimed missed sale is priced exactly as the
 * till would have priced it (PLAN §12 Phase 3: server-resolved rates and
 * caps).
 *
 * Resolution walks the live PUBLISHED promotion candidates best-rate first
 * and settles on the first one that actually pays:
 *
 * - A promotion is a BOOST: once a candidate's rate no longer exceeds the
 *   standing rate, no later (lower-rate) candidate can either — the walk
 *   stops and the standing terms price the row, unstamped.
 * - Uncapped candidate → promo rate, fee from the promo rate's §4 tier.
 * - Capped candidate with headroom → the remaining per-customer headroom
 *   clips the reward via calculateCapped, the fee following the reward
 *   actually GRANTED (ceiling from granted).
 * - Capped candidate with NO headroom is exhausted FOR THIS CUSTOMER only:
 *   the walk continues, so a second, lower-rate live promotion still prices
 *   the sale instead of everything collapsing to the standing rate.
 *
 * FLOOR (documented, tested): a promotion never pays less than no promotion
 * at all. When the clip falls below what the standing rate would grant, the
 * standing terms win and the row is NOT stamped — the promo priced nothing,
 * so it must not absorb the row into its cap accounting, and the customer's
 * payout stays monotonic (1 laari of headroom can never earn less than 0).
 *
 * RACE SAFETY: each capped candidate's used-cap SUM runs under
 * pg_advisory_xact_lock(promotion, customer) inside the caller's DB
 * transaction — two concurrent credits for the same customer on the same
 * promotion serialise, so both can never fit under the same remaining
 * headroom. Locks are transaction-scoped and release at commit/rollback;
 * candidates are walked in a deterministic order, so two sessions can never
 * deadlock on each other. MUST be called inside DB::transaction.
 */
final readonly class TermsResolver
{
    public function __construct(
        private CashbackCalculator $calculator,
        private PromotionResolver $promotions,
    ) {}

    /**
     * @return array{0: CashbackResult, 1: int|null} the money result and the promotion stamped on the row, if any
     */
    public function resolve(
        int $merchantId,
        ?int $branchId,
        Laari $eligible,
        int $standingBp,
        int $customerId,
        CarbonImmutable $occurredAt,
    ): array {
        $standing = $this->calculator->calculate($eligible, Rate::cashback($standingBp));

        foreach ($this->promotions->candidatesAt($merchantId, $branchId, $eligible->value(), $occurredAt) as $promotion) {
            if ($promotion->rate_bp <= $standingBp) {
                break; // rate-descending order: no later candidate boosts either
            }

            $promoRate = Rate::cashback($promotion->rate_bp);

            if ($promotion->max_cashback_per_customer_laari === null) {
                return [$this->calculator->calculate($eligible, $promoRate), $promotion->id];
            }

            // Two int4 keys (classid, objid); modulo keeps huge ids in range —
            // a collision merely over-serialises, never under-locks.
            DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [
                $promotion->id % 2147483647,
                $customerId % 2147483647,
            ]);

            // Cap consumed = cashback already granted to this customer on this
            // promotion, excluding reversed rows (a refunded sale returns its
            // headroom). Stored integers only — never recomputed.
            $used = (int) Transaction::query()
                ->where('promotion_id', $promotion->id)
                ->where('customer_id', $customerId)
                ->where('state', '!=', TransactionState::Reversed->value)
                ->sum('cashback_laari');

            $remaining = $promotion->max_cashback_per_customer_laari - $used;

            if ($remaining <= 0) {
                continue; // exhausted for this customer — the next candidate may still pay
            }

            $clipped = $this->calculator->calculateCapped($eligible, $promoRate, Laari::of($remaining));

            if ($clipped->cashbackLaari < $standing->cashbackLaari) {
                continue; // the FLOOR: this clip pays less than no promotion — never grant it
            }

            return [$clipped, $promotion->id];
        }

        return [$standing, null];
    }
}
