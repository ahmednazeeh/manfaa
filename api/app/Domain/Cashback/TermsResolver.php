<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\CashbackResult;
use App\Domain\Money\FeeTier;
use App\Domain\Money\Laari;
use App\Domain\Money\Rate;
use App\Domain\Money\TierSchedule;
use App\Domain\Platform\FeePromotionPolicy;
use App\Domain\Platform\FeeRelief;
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
 *
 * ────────────────────────────────────────────────────────────────────────
 * PLATFORM FEE PROMOTIONS (owner, 2026-08-25) — THE FEE SEAM
 * ────────────────────────────────────────────────────────────────────────
 *
 * This class is the ONE choke point where a cashback rate becomes a platform
 * fee, so it is the one place a fee promotion is applied. The rule is a
 * single line, and it is applied at exactly the point the TIER has answered,
 * in BOTH resolve() and resolveLines(), so a split sale and a plain one
 * price identically:
 *
 *     charged fee bp = min(the promotion's fee bp, the tier's fee bp)
 *
 * TWO KINDS, and when both apply the MERCHANT WINS — the LOWER fee prices
 * the sale. That comparison lives in FeePromotionPolicy, which hands this
 * class a single already-decided FeeRelief:
 *
 *   INTRODUCTORY   every merchant's first X days from `merchants.approved_at`,
 *                  in business days. A merchant approved before the promotion
 *                  was switched on is not retro-enrolled: their window is a
 *                  function of their own approval date, and if it has passed
 *                  they get nothing.
 *   PLATFORM-WIDE  a superadmin-set [from, to) window covering everybody.
 *
 * DO NOT CONFUSE the `$promoFeeBp` locals below with any of this: those are
 * about CASHBACK promotions (App\Models\Promotion — a merchant paying their
 * customers MORE), which is a different feature entirely. A fee promotion
 * lowers what MANFAA charges the merchant, and the two compose without
 * knowing about each other: a cashback promotion picks the rate, the rate
 * picks the tier, and the fee promotion caps the tier's fee.
 *
 * MARKETPLACE ORDER FEES ARE **NOT** PROMOTED — a deliberate decision, not
 * an oversight (the GST round missed that path at first, so this round says
 * it out loud). `suborders.order_fee_bp` is a SEPARATE price list: it comes
 * from `merchant_marketplace_profiles.order_fee_bp` or the
 * `marketplace_fee_bp` platform setting, it is a percentage of an order's
 * items rather than of a cashback reward, it sits on a different scale
 * entirely (a 5% order fee against §4 tier fees of 0.25–1.00%), and it never
 * posts to ledger account 4100. Three consequences, all of them the reason:
 * the "a promotion may never cost the merchant more" refusal has no tier to
 * check a fee against there; one promotional basis-point figure cannot
 * honestly mean both prices; and a forgone-fee figure derived on the
 * marketplace side could not be reconciled against the ledger-built earnings
 * report. The lever a superadmin already has for a marketplace shop is that
 * shop's own `order_fee_bp`, which can be set to zero per merchant.
 * CheckoutService carries a pointer back to this paragraph.
 *
 * WHAT GETS FROZEN. resolve() and resolveLines() return a PricedFee (and
 * per-line list figures) carrying the promotion's kind, its offered fee, the
 * tier fee it displaced and the fee that would have been charged — which
 * CreditRecorder stamps onto the row and its lines beside `fee_bp`. Nothing
 * downstream ever re-reads the live settings: ending a promotion, changing
 * its fee or moving its window prices NEW sales only.
 */
final class TermsResolver
{
    private ?bool $linesTableExists = null;

    public function __construct(
        private readonly CashbackCalculator $calculator,
        private readonly PromotionResolver $promotions,
        private readonly FeeTierScheduleResolver $feeTiers,
        private readonly FeePromotionPolicy $feePromotions,
    ) {}

    /**
     * @return array{0: CashbackResult, 1: int|null, 2: PricedFee} the money result, the
     *                                                             cashback promotion stamped on the row (if any), and the platform
     *                                                             fee actually charged beside what the tier would have charged
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
        // back to the static §4 FeeTier map, which is the same table the
        // migration seeds.
        $schedule = $this->feeTiers->at($occurredAt);

        // The fee promotion in force for THIS merchant at THIS instant —
        // resolved once for the whole walk, because every candidate below is
        // the same sale on the same day for the same store.
        $relief = $this->feePromotions->reliefFor($merchantId, $occurredAt);

        [$standing, $standingFee] = $this->priceAt(
            $eligible,
            Rate::cashback($standingBp),
            $this->tierFeeBp($schedule, $standingBp),
            $relief,
        );

        foreach ($this->promotions->candidatesAt($merchantId, $branchId, $eligible->value(), $occurredAt) as $promotion) {
            if ($promotion->rate_bp <= $standingBp) {
                break; // rate-descending order: no later candidate boosts either
            }

            $promoRate = Rate::cashback($promotion->rate_bp);
            $promoTierFeeBp = $this->tierFeeBp($schedule, $promotion->rate_bp);

            if ($promotion->max_cashback_per_customer_laari === null) {
                [$result, $fee] = $this->priceAt($eligible, $promoRate, $promoTierFeeBp, $relief);

                return [$result, $promotion->id, $fee];
            }

            $remaining = $this->remainingCap($promotion, $customerId);

            if ($remaining <= 0) {
                continue; // exhausted for this customer — the next candidate may still pay
            }

            [$clipped, $clippedFee] = $this->priceAt($eligible, $promoRate, $promoTierFeeBp, $relief, Laari::of($remaining));

            if ($clipped->cashbackLaari < $standing->cashbackLaari) {
                continue; // the FLOOR: this clip pays less than no promotion — never grant it
            }

            return [$clipped, $promotion->id, $clippedFee];
        }

        return [$standing, null, $standingFee];
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
     * single-rate rule that only accruing rows consult promotions. It
     * governs CASHBACK promotions only — the platform FEE relief is a
     * property of the fee tier and is resolved either way; a zeroed row
     * carries no fee to relieve, so the stamp comes out empty anyway.
     *
     * $frozenFeeRelief is how an AMENDMENT re-prices a lined sale under the
     * fee promotion the row was ORIGINALLY rung up under
     * (FeeRelief::fromStamp on the row's own columns) instead of whatever is
     * running today. Null means "resolve the live promotion", which is what
     * a new sale wants; FeeRelief::none() is a legitimate frozen answer and
     * means "this row was priced with no fee promotion at all".
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
        ?FeeRelief $frozenFeeRelief = null,
    ): PricedLineSet {
        $schedule = $this->feeTiers->at($occurredAt);
        $relief = $frozenFeeRelief ?? $this->feePromotions->reliefFor($merchantId, $occurredAt);

        // The base-rate snapshot for the transaction row: the standing
        // terms at occurred_at, resolved exactly as the single-rate path
        // resolves them (rate + its fee tier under the schedule in force,
        // capped by any fee promotion).
        [$standing, $standingFee] = $this->priceAt(
            $eligible,
            Rate::cashback($standingBp),
            $this->tierFeeBp($schedule, $standingBp),
            $relief,
        );

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

                [$priced, $promoPricedAny, $feeReduced] = $this->priceLines($lines, $standingBp, $schedule, $promotion, $capRemaining, $relief);

                if ($promoPricedAny) {
                    return $this->lineSet($priced, $promotion->id, $standing, $standingFee, $relief, $feeReduced);
                }

                // Every boosted line hit the per-line floor — this promo
                // priced nothing, so it must not stamp the row or absorb it
                // into cap accounting; a lower-rate candidate may still pay.
            }
        }

        [$priced, , $feeReduced] = $this->priceLines($lines, $standingBp, $schedule, null, null, $relief);

        return $this->lineSet($priced, null, $standing, $standingFee, $relief, $feeReduced);
    }

    /**
     * @param  list<PricedLine>  $priced
     */
    private function lineSet(
        array $priced,
        ?int $promotionId,
        CashbackResult $standing,
        PricedFee $standingFee,
        FeeRelief $relief,
        bool $anyLineFeeReduced,
    ): PricedLineSet {
        // Stamp the promotion on the SALE only when it actually made
        // something cheaper — the row's own base fee, or any line's. A
        // promotion running but beating nobody's tier leaves the row
        // indistinguishable from one priced with no promotion at all, which
        // is precisely what it is.
        //
        // The header's `list_fee_bp` below is NOT gated on the same
        // question, and deliberately: it is the before-price of the
        // BASE-RATE SNAPSHOT — the matched pair of `rowFeeBp` — so a sale
        // where only a CATEGORY line was reduced is stamped with the kind,
        // the offered fee and the forgone laari while `list_fee_bp` stays
        // null, because the base rate's own fee genuinely was not reduced
        // and the two header fee columns must stay costed on one rate. Each
        // line carries its own before-price; see PricedLineSet.
        $applied = $standingFee->reduced() || $anyLineFeeReduced;

        return new PricedLineSet(
            $priced,
            $promotionId,
            $standing->rateBp,
            $standing->feeBp,
            $applied ? $relief->kind : null,
            $applied ? $relief->feeBp : null,
            $standingFee->listFeeBp(),
        );
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
     * @return array{0: list<PricedLine>, 1: bool, 2: bool} the priced lines, whether any line
     *                                                      priced under the cashback promotion, and whether the fee promotion
     *                                                      made any line cheaper
     */
    private function priceLines(
        array $lines,
        int $standingBp,
        ?TierSchedule $schedule,
        ?Promotion $promotion,
        ?int $capRemaining,
        FeeRelief $relief,
    ): array {
        $priced = [];
        $promoPricedAny = false;
        $feeReducedAny = false;

        foreach ($lines as $sort => $line) {
            $category = $line->category;

            if ($category?->mode === 'excluded') {
                // Excluded stays 0 even during promotions — of either kind.
                // There is no fee on nothing to relieve.
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

            [$result, $fee] = $this->priceAt($amount, Rate::cashback($ownBp), $this->tierFeeBp($schedule, $ownBp), $relief);
            $by = $ownBy;

            if ($promotion !== null && $promotion->rate_bp > $ownBp) {
                $promoRate = Rate::cashback($promotion->rate_bp);
                $promoTierFeeBp = $this->tierFeeBp($schedule, $promotion->rate_bp);

                if ($capRemaining === null) {
                    [$result, $fee] = $this->priceAt($amount, $promoRate, $promoTierFeeBp, $relief);
                    $by = 'promotion';
                    $promoPricedAny = true;
                } else {
                    [$clipped, $clippedFee] = $this->priceAt($amount, $promoRate, $promoTierFeeBp, $relief, Laari::of($capRemaining));

                    if ($clipped->cashbackLaari >= $result->cashbackLaari) {
                        $result = $clipped;
                        $fee = $clippedFee;
                        $by = 'promotion';
                        $promoPricedAny = true;
                        $capRemaining -= $clipped->cashbackLaari;
                    }
                    // else: per-line FLOOR — own terms stand, no cap consumed.
                }
            }

            $feeReducedAny = $feeReducedAny || $fee->reduced();

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
                listFeeBp: $fee->listFeeBp(),
                listFeeLaari: $fee->reduced() ? $fee->listFeeLaari : null,
            );
        }

        return [$priced, $promoPricedAny, $feeReducedAny];
    }

    /**
     * THE FEE SEAM ITSELF: one priced unit — a whole sale, or a line —
     * costed twice, once at the fee it was charged and once at the fee the
     * TIER would have charged.
     *
     * The second computation is skipped whenever the promotion changed
     * nothing (`chargedFeeBp === $tierFeeBp`, which is every sale on the
     * platform today), so the ordinary path does exactly the arithmetic it
     * always did. When a promotion DID apply, the two runs differ only in
     * fee_bp — the cashback, and therefore the cap clip, is identical — so
     * the difference between their fees is the forgone fee for this unit,
     * computed with the same §4 ceiling rounding rather than re-derived
     * later from two basis points.
     *
     * @return array{0: CashbackResult, 1: PricedFee}
     */
    private function priceAt(Laari $amount, Rate $rate, int $tierFeeBp, FeeRelief $relief, ?Laari $cap = null): array
    {
        $chargedFeeBp = $relief->chargedFeeBp($tierFeeBp);

        $charged = $cap === null
            ? $this->calculator->calculate($amount, $rate, $chargedFeeBp)
            : $this->calculator->calculateCapped($amount, $rate, $cap, $chargedFeeBp);

        if ($chargedFeeBp === $tierFeeBp) {
            return [$charged, PricedFee::none($tierFeeBp, $charged->feeLaari)];
        }

        $list = $cap === null
            ? $this->calculator->calculate($amount, $rate, $tierFeeBp)
            : $this->calculator->calculateCapped($amount, $rate, $cap, $tierFeeBp);

        return [$charged, new PricedFee($relief, $tierFeeBp, $chargedFeeBp, $list->feeLaari, $charged->feeLaari)];
    }

    /**
     * The §4 fee a rate is billed at under the schedule in force, as a
     * CONCRETE integer — because a fee promotion has to be able to compare
     * itself against it, and `null` (the calculator's "use the static map"
     * signal) cannot be compared to anything.
     *
     * Resolving it here is not a change of behaviour: passing null to
     * CashbackCalculator::calculate makes it call FeeTier::feeBpFor($rateBp)
     * on the very same rate, so this returns the identical integer by the
     * identical route.
     *
     * A SURVIVAL FALLBACK, for a rate the coverage invariant does NOT
     * protect — a line's own category rate, or the sale's base rate when
     * that base is a per-sale override:
     *
     * A per-sale override (PLAN §1) is validated against the ACTIVE
     * schedule's ceiling at submission time, so a BACKDATED sale carrying
     * one lands in exactly the category-rate situation described below: the
     * narrower schedule in force at occurred_at may not price it. Same
     * rescue, same reason.
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
     * the past. Fall back to the static §4 FeeTier map, which covers the
     * full structural 50-2000 bp range every category rate is validated
     * against — the same fallback used when no schedule row exists at all
     * (and the same philosophy as FeeTierScheduleResolver::tryFeeBpAt).
     *
     * Standing and promotion rates never need this branch: the coverage
     * invariant (TierScheduleService) guarantees the schedule governing
     * every instant they are IN FORCE prices them, and both are
     * effective-dated so occurred_at always resolves a covered pairing.
     */
    private function tierFeeBp(?TierSchedule $schedule, int $ownBp): int
    {
        try {
            return $schedule?->feeBpFor($ownBp) ?? FeeTier::feeBpFor($ownBp);
        } catch (OutOfRangeException) {
            return FeeTier::feeBpFor($ownBp);
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
