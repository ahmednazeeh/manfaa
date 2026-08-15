<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\CashbackResult;
use App\Domain\Money\Laari;
use App\Domain\Money\Rate;
use App\Domain\Money\TierSchedule;
use App\Domain\Platform\FeeTierScheduleResolver;
use App\Domain\Promotions\PromotionResolver;
use App\Models\Promotion;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OutOfRangeException;

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
 *
 * LINE-ITEM PRICING (Task #25, PLAN §1 product-category rates):
 * resolveLines() prices a lined credit per line under the same law —
 * excluded categories earn 0 (always, promos included), category overrides
 * earn their own rate, the default bucket earns the standing rate, and a
 * live promotion lifts every non-excluded line to
 * max(promo rate, the line's own rate). Per-line §4 ceiling rounding;
 * totals are the SUM of the stored line integers.
 */
final class TermsResolver
{
    private ?bool $linesTableExists = null;

    public function __construct(
        private readonly CashbackCalculator $calculator,
        private readonly PromotionResolver $promotions,
        private readonly FeeTierScheduleResolver $feeTiers,
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
        // The fee tier schedule effective at occurred_at (admin-managed,
        // append-only) prices every candidate below; a null schedule falls
        // back to the static §4 FeeTier map inside the calculator, which is
        // the same table the migration seeds.
        $schedule = $this->feeTiers->at($occurredAt);

        $standing = $this->calculator->calculate($eligible, Rate::cashback($standingBp), $schedule?->feeBpFor($standingBp));

        foreach ($this->promotions->candidatesAt($merchantId, $branchId, $eligible->value(), $occurredAt) as $promotion) {
            if ($promotion->rate_bp <= $standingBp) {
                break; // rate-descending order: no later candidate boosts either
            }

            $promoRate = Rate::cashback($promotion->rate_bp);
            $promoFeeBp = $schedule?->feeBpFor($promotion->rate_bp);

            if ($promotion->max_cashback_per_customer_laari === null) {
                return [$this->calculator->calculate($eligible, $promoRate, $promoFeeBp), $promotion->id];
            }

            $remaining = $this->remainingCap($promotion, $customerId);

            if ($remaining <= 0) {
                continue; // exhausted for this customer — the next candidate may still pay
            }

            $clipped = $this->calculator->calculateCapped($eligible, $promoRate, Laari::of($remaining), $promoFeeBp);

            if ($clipped->cashbackLaari < $standing->cashbackLaari) {
                continue; // the FLOOR: this clip pays less than no promotion — never grant it
            }

            return [$clipped, $promotion->id];
        }

        return [$standing, null];
    }

    /**
     * Line-item resolution for a lined credit. Same promotion walk as
     * resolve(), applied to the whole SALE: candidates match on branch
     * scope and min_purchase against the WHOLE eligible amount, best rate
     * first. A candidate prices the sale when at least one line actually
     * earns under it; otherwise the walk continues (exhausted caps and
     * per-line floors fall through exactly like the single-rate path).
     *
     * Per line:
     * - excluded category → 0/0/0/0, priced_by `excluded` — exclusions
     *   hold even during promotions (PLAN §1 flagged assumption);
     * - otherwise the line's OWN rate is its category override or, for the
     *   default (null) bucket, the standing rate — priced_by `category` /
     *   `standing`;
     * - a live promotion lifts every non-excluded line to
     *   max(promo rate, own rate); promo-priced lines record priced_by
     *   `promotion`.
     *
     * PER-CUSTOMER CAP: only promo-priced lines consume headroom. Lines are
     * processed in SUBMITTED order; each boosted line takes
     * min(its normal promo cashback, remaining headroom) via
     * calculateCapped — fee follows the reward GRANTED — and the remaining
     * headroom shrinks as it goes. PER-LINE FLOOR (the resolve() FLOOR
     * applied at line grain): when the clip would grant a line less than
     * its own rate would, the line reprices at its own terms, consumes no
     * headroom, and is not stamped — a promotion never pays less than no
     * promotion, per line. Cap reads run under the same advisory lock as
     * the single-rate path. MUST be called inside DB::transaction.
     *
     * The returned rowRateBp/rowFeeBp are the STANDING resolution — the
     * transaction row freezes the base-rate snapshot exactly as the
     * single-rate path does; per-line truth lives in the lines.
     *
     * $consultPromotions false (zeroed rows: below-minimum / suspended
     * ingestion) prices lines at their own terms only, mirroring the
     * single-rate rule that only accruing rows consult promotions.
     *
     * @param  list<LineInput>  $lines
     */
    public function resolveLines(
        int $merchantId,
        ?int $branchId,
        array $lines,
        Laari $eligible,
        int $standingBp,
        int $customerId,
        CarbonImmutable $occurredAt,
        bool $consultPromotions = true,
    ): PricedLineSet {
        $schedule = $this->feeTiers->at($occurredAt);

        // The base-rate snapshot for the transaction row: the standing
        // terms at occurred_at, resolved exactly as the single-rate path
        // resolves them (rate + its fee tier under the schedule in force).
        $standing = $this->calculator->calculate($eligible, Rate::cashback($standingBp), $schedule?->feeBpFor($standingBp));

        if ($consultPromotions) {
            foreach ($this->promotions->candidatesAt($merchantId, $branchId, $eligible->value(), $occurredAt) as $promotion) {
                if (! $this->boostsAnyLine($promotion, $lines, $standingBp)) {
                    break; // rate-descending order: no later candidate boosts any line either
                }

                $capRemaining = null;

                if ($promotion->max_cashback_per_customer_laari !== null) {
                    $capRemaining = $this->remainingCap($promotion, $customerId);

                    if ($capRemaining <= 0) {
                        continue; // exhausted for this customer — the next candidate may still pay
                    }
                }

                [$priced, $promoPricedAny] = $this->priceLines($lines, $standingBp, $schedule, $promotion, $capRemaining);

                if ($promoPricedAny) {
                    return new PricedLineSet($priced, $promotion->id, $standing->rateBp, $standing->feeBp);
                }

                // Every boosted line hit the per-line floor — this promo
                // priced nothing, so it must not stamp the row or absorb it
                // into cap accounting; a lower-rate candidate may still pay.
            }
        }

        [$priced] = $this->priceLines($lines, $standingBp, $schedule, null, null);

        return new PricedLineSet($priced, null, $standing->rateBp, $standing->feeBp);
    }

    /**
     * @param  list<LineInput>  $lines
     */
    private function boostsAnyLine(Promotion $promotion, array $lines, int $standingBp): bool
    {
        foreach ($lines as $line) {
            if ($line->category?->mode === 'excluded') {
                continue;
            }

            if ($promotion->rate_bp > ($line->category?->rate_bp ?? $standingBp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prices every line under the given (possibly null) promotion context.
     * $capRemaining null means uncapped; an integer is the per-customer
     * headroom, consumed line by line in submitted order.
     *
     * @param  list<LineInput>  $lines
     * @return array{0: list<PricedLine>, 1: bool} the priced lines and whether any line priced under the promotion
     */
    private function priceLines(
        array $lines,
        int $standingBp,
        ?TierSchedule $schedule,
        ?Promotion $promotion,
        ?int $capRemaining,
    ): array {
        $priced = [];
        $promoPricedAny = false;

        foreach ($lines as $sort => $line) {
            $category = $line->category;

            if ($category?->mode === 'excluded') {
                // Excluded stays 0 even during promotions.
                $priced[] = new PricedLine(
                    categoryId: $category->id,
                    slug: $category->slug,
                    nameEn: $category->name_en,
                    amountLaari: $line->amountLaari,
                    rateBp: 0,
                    feeBp: 0,
                    cashbackLaari: 0,
                    feeLaari: 0,
                    pricedBy: 'excluded',
                    sort: $sort,
                );

                continue;
            }

            $ownBp = $category?->rate_bp ?? $standingBp;
            $ownBy = $category === null ? 'standing' : 'category';
            $amount = Laari::of($line->amountLaari);

            $result = $this->calculator->calculate($amount, Rate::cashback($ownBp), $this->ownFeeBp($schedule, $ownBp));
            $by = $ownBy;

            if ($promotion !== null && $promotion->rate_bp > $ownBp) {
                $promoRate = Rate::cashback($promotion->rate_bp);
                $promoFeeBp = $schedule?->feeBpFor($promotion->rate_bp);

                if ($capRemaining === null) {
                    $result = $this->calculator->calculate($amount, $promoRate, $promoFeeBp);
                    $by = 'promotion';
                    $promoPricedAny = true;
                } else {
                    $clipped = $this->calculator->calculateCapped($amount, $promoRate, Laari::of($capRemaining), $promoFeeBp);

                    if ($clipped->cashbackLaari >= $result->cashbackLaari) {
                        $result = $clipped;
                        $by = 'promotion';
                        $promoPricedAny = true;
                        $capRemaining -= $clipped->cashbackLaari;
                    }
                    // else: per-line FLOOR — own terms stand, no cap consumed.
                }
            }

            $priced[] = new PricedLine(
                categoryId: $category?->id,
                slug: $category?->slug,
                nameEn: $category?->name_en,
                amountLaari: $line->amountLaari,
                rateBp: $result->rateBp,
                feeBp: $result->feeBp,
                cashbackLaari: $result->cashbackLaari,
                feeLaari: $result->feeLaari,
                pricedBy: $by,
                sort: $sort,
            );
        }

        return [$priced, $promoPricedAny];
    }

    /**
     * The schedule fee for a line's OWN rate — with a survival fallback.
     *
     * Category override rates carry no effective-dated history and are
     * validated now-forward only (ProductCategoriesController /
     * TierScheduleService::assertPricedThrough from now()), so a BACKDATED
     * lined credit can carry a category rate that the narrower schedule in
     * force at occurred_at never priced — e.g. a 15% category set under
     * today's 50-2000 schedule on a sale from before it took effect, when
     * the seeded 50-1000 row governed. feeBpFor throws OutOfRangeException
     * there, which would 500 the credit inside its DB transaction (both the
     * manual path and POST /v1/transactions, including the §7
     * suspended-merchant ingestion that must never error) and leave the
     * basket permanently uncreditable — schedules cannot be published into
     * the past. Fall back to null: the calculator then prices the fee from
     * the static §4 FeeTier map, which covers the full structural
     * 50-2000 bp range every category rate is validated against — the same
     * fallback used when no schedule row exists at all (and the same
     * philosophy as FeeTierScheduleResolver::tryFeeBpAt).
     *
     * Standing and promotion rates never need this branch: the coverage
     * invariant (TierScheduleService) guarantees the schedule governing
     * every instant they are IN FORCE prices them, and both are
     * effective-dated so occurred_at always resolves a covered pairing.
     */
    private function ownFeeBp(?TierSchedule $schedule, int $ownBp): ?int
    {
        try {
            return $schedule?->feeBpFor($ownBp);
        } catch (OutOfRangeException) {
            return null;
        }
    }

    /**
     * Takes the advisory lock for (promotion, customer) and answers the
     * remaining per-customer headroom. Cap consumed = cashback already
     * granted to this customer on this promotion, excluding reversed rows
     * (a refunded sale returns its headroom) — stored integers only, never
     * recomputed. For LINED transactions only the promotion-priced lines
     * count: lines priced by their own category/standing rate are not
     * cap-constrained and must not consume headroom either.
     */
    private function remainingCap(Promotion $promotion, int $customerId): int
    {
        // Two int4 keys (classid, objid); modulo keeps huge ids in range —
        // a collision merely over-serialises, never under-locks.
        DB::select('SELECT pg_advisory_xact_lock(?::int, ?::int)', [
            $promotion->id % 2147483647,
            $customerId % 2147483647,
        ]);

        $base = Transaction::query()
            ->where('promotion_id', $promotion->id)
            ->where('customer_id', $customerId)
            ->where('state', '!=', TransactionState::Reversed->value);

        // Deploy-order safety (same probe as FeeTierScheduleResolver): code
        // can reach production before the transaction_lines migration runs,
        // and this query executes INSIDE the credit DB transaction where a
        // failed query would abort the credit.
        if (! ($this->linesTableExists ??= Schema::hasTable('transaction_lines'))) {
            $used = (int) $base->sum('cashback_laari');

            return $promotion->max_cashback_per_customer_laari - $used;
        }

        $unlined = (int) (clone $base)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('transaction_lines')
                    ->whereColumn('transaction_lines.transaction_id', 'transactions.id');
            })
            ->sum('cashback_laari');

        $lined = (int) TransactionLine::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_lines.transaction_id')
            ->where('transactions.promotion_id', $promotion->id)
            ->where('transactions.customer_id', $customerId)
            ->where('transactions.state', '!=', TransactionState::Reversed->value)
            ->where('transaction_lines.priced_by', 'promotion')
            ->sum('transaction_lines.cashback_laari');

        return $promotion->max_cashback_per_customer_laari - $unlined - $lined;
    }
}
