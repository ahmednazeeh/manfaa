<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Ledger\Postings;
use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\Laari;
use App\Domain\Money\Rate;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Domain\Platform\RateNotPricedException;
use App\Domain\Platform\TierScheduleService;
use App\Domain\Promotions\PromotionResolver;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The one earning path, shared by every origin (manual panel credits,
 * §9.2 POS ingestion). A single call creates the transaction row, writes
 * its creation event, posts the accrual journal, and routes the row into
 * its first real state — atomically, exactly as ManualCreditService did in
 * Phase 0; that service now delegates here.
 *
 * $ineligibleReason is the §7 suspended-merchant lever: when set, the sale
 * is still recorded (ingestion never stops) but with zero cashback, zero
 * fee, no journal, and an immediate terminal reversal — the exact
 * below-minimum mechanics with a different reason code.
 *
 * Backdated credits (PLAN §1, decision 2026-08-14 late): a sale older than
 * the merchant's validation window + 3 days skips on_hold entirely. It goes
 * straight to payable_unfunded with the 15-day clock starting NOW, carries
 * reason_code `backdated_final` and the permanent `backdated` flag, and can
 * never be reversed by the merchant or their vendor (ReversalService answers
 * 409 backdated_irreversible — admin adjustment only). One rule here covers
 * the manual panel path AND /v1. on_hold is now fraud/velocity review only.
 *
 * Promotions (PLAN §12 Phase 3): TermsResolver walks the live PUBLISHED
 * candidates covering occurred_at, the sale's branch and its minimum
 * purchase — promo rate pricing the row, fee following the promo rate's §4
 * tier, per-customer caps settled under a Postgres advisory transaction
 * lock, exhausted candidates falling through to the next live promotion,
 * and the whole result floored at the standing terms (a promotion never
 * pays less than no promotion). A sale matching no candidate simply earns
 * the standing rate; it is never rejected.
 *
 * Line-item pricing (Task #25): when $lines is non-null the credit is a
 * LINED credit — TermsResolver::resolveLines prices each line (excluded →
 * 0, category override → its rate, default bucket → standing, live promo
 * lifting non-excluded lines to max(promo, own)), the per-line integers
 * are stored as append-only transaction_lines snapshots, and the
 * transaction row's totals are the SUM of those stored integers. The row's
 * rate_bp/fee_bp remain the STANDING resolution (base-rate snapshot);
 * per-line truth lives in the lines. With $lines null, the single-rate
 * path below runs untouched.
 *
 * Per-sale rate override (PLAN §1, decision 2026-08-15): $overrideRateBp is
 * a merchant-chosen rate for THIS SALE ONLY, arriving on the wire as
 * `cashback_rate_percent`. It is validated here — once, for every origin —
 * and then simply BECOMES THE BASE RATE the sale prices at, in place of the
 * standing rate: the row freezes it, the fee tier follows it, and with line
 * pricing every line that would otherwise price at standing prices at the
 * override instead (category overrides and exclusions are untouched, and
 * the promotion floor still lifts any line a live promo would pay more on).
 * Two refusals, both before anything is written:
 *
 *  - above the ACTIVE fee tier schedule's ceiling → RateNotPricedException
 *    (`rate_not_priced`): the platform fee for that rate is priced nowhere;
 *  - below the rate the sale would otherwise earn — the standing rate, or a
 *    live promotion covering this sale → RateBelowAdvertisedException
 *    (`rate_below_advertised`): the advertised rate is a public promise, so
 *    an override may only boost.
 *
 * Two rules keep that gate from doing harm of its own, both in
 * assertOverridable and the $baseBp line below: an override EQUAL to the
 * advertised rate is a no-op (it never displaces the promotion that
 * advertised it, cap and all), and a ZEROED row — suspended-merchant
 * ingestion, below-minimum — ignores the field entirely rather than
 * refusing a sale that grants no cashback either way.
 */
final readonly class CreditRecorder
{
    /** Clock-skew allowance before an occurred_at counts as future-dated. */
    private const int FUTURE_SKEW_MINUTES = 5;

    /**
     * Days past the merchant's validation window before a credit counts as
     * BACKDATED (PLAN §1 "Backdated credits").
     */
    private const int STALE_GRACE_DAYS = 3;

    /**
     * The state qualifier a backdated credit carries: it never sat in the
     * refund window, so it was never provisional — it is final the moment it
     * is recorded, payable now, and merchant-irreversible.
     */
    public const string BACKDATED_REASON = 'backdated_final';

    /**
     * Would a sale at this instant skip the refund window?
     *
     * PUBLIC because the HTTP layer has to ask the same question BEFORE
     * recording: the till app must refuse to silently create an
     * irreversible, immediately-payable credit when a device clock is
     * wrong (PLAN-mobile-api.md M5). A second copy of this arithmetic in a
     * controller is exactly how the two would drift, so there is one.
     */
    public static function wouldBeBackdated(
        Merchant $merchant,
        CarbonImmutable $occurredAt,
        CarbonImmutable $now,
    ): bool {
        return $occurredAt->utc()->isBefore(
            $now->utc()->subDays($merchant->validation_window_days + self::STALE_GRACE_DAYS),
        );
    }

    public function __construct(
        private TransitionService $transitions,
        private Postings $postings,
        private CashbackCalculator $calculator,
        private TermsResolver $terms,
        private TierScheduleService $schedules,
        private PromotionResolver $promotions,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  list<LineInput>|null  $lines  parsed line splits (LineSetParser) — null for a single-rate credit
     * @param  int|null  $overrideRateBp  per-sale rate override in basis points (wire: `cashback_rate_percent`)
     *
     * @throws RateNotPricedException|RateBelowAdvertisedException
     */
    public function record(
        Merchant $merchant,
        Actor $actor,
        string $origin,
        string $customerCode,
        string $invoiceNo,
        Laari $eligible,
        ?Laari $saleAmount,
        CarbonImmutable $occurredAt,
        ?int $branchId = null,
        ?string $idempotencyKey = null,
        ?string $ineligibleReason = null,
        ?array $lines = null,
        ?int $overrideRateBp = null,
    ): Transaction {
        $customer = Customer::query()->where('customer_code', $customerCode)->first()
            ?? throw CustomerNotFoundException::forCode($customerCode);

        $now = CarbonImmutable::now('UTC');
        $occurredAt = $occurredAt->utc();

        if ($occurredAt->isAfter($now->addMinutes(self::FUTURE_SKEW_MINUTES))) {
            throw FutureDatedTransactionException::at($occurredAt);
        }

        // Rate and fee are frozen at occurred_at even when the cashback is
        // zeroed (below minimum, suspended merchant) — the row must evidence
        // the terms it met.
        $standingBp = $this->resolveRateBp($merchant, $occurredAt);

        $ineligible = $ineligibleReason !== null;
        $belowMinimum = $eligible->value() < $merchant->min_eligible_laari;

        // PLAN §1 "Backdated credits": a sale older than the merchant's
        // validation window (plus the grace days) has already outlived the
        // refund window it would have waited in. It therefore skips
        // awaiting_validation AND on_hold entirely — see the routing below.
        $backdated = self::wouldBeBackdated($merchant, $occurredAt, $now);

        // Nothing ever accrues on an ineligible or below-minimum sale, and
        // the row goes terminal immediately. Suspension outranks the minimum:
        // the reason the cashier sees must be the one that stops accrual
        // resuming (§7).
        $zeroed = $ineligible || $belowMinimum;
        $reason = $ineligible ? $ineligibleReason : ($belowMinimum ? 'below_minimum' : null);

        // A per-sale override replaces the standing rate as the BASE rate
        // this sale prices at — everything downstream (the row snapshot,
        // the fee tier, the default line bucket, the promotion floor) then
        // works exactly as it always has, one rung higher.
        //
        // A ZEROED row never consults it. Those sales grant no cashback at
        // all, so no override could have been applied to them, and refusing
        // one would break the two rules that outrank it: §7 "suspension
        // stops creation, not ingestion" (a suspended merchant's till must
        // still be ANSWERED, with the sale recorded ineligible) and §9.2
        // "below-minimum sales return 200 — do not reject the POST". The
        // row freezes the standing terms it failed against, exactly as it
        // does with no override in the payload.
        $baseBp = $overrideRateBp === null || $zeroed
            ? $standingBp
            : $this->assertOverridable($merchant, $branchId, $eligible, $occurredAt, $standingBp, $overrideRateBp);

        try {
            return DB::transaction(function () use ($merchant, $actor, $origin, $customer, $invoiceNo, $eligible, $saleAmount, $occurredAt, $now, $branchId, $idempotencyKey, $baseBp, $zeroed, $reason, $backdated, $lines): Transaction {
                $priced = null;

                if ($lines === null) {
                    // Promo-aware terms at occurred_at (PLAN §12 Phase 3): only
                    // rows that actually accrue consult promotions — a zeroed row
                    // freezes the standing terms it failed against.
                    [$result, $promotionId] = $zeroed
                        ? [$this->calculator->calculate($eligible, Rate::cashback($baseBp)), null]
                        : $this->terms->resolve($merchant->id, $branchId, $eligible, $baseBp, $customer->id, $occurredAt);

                    $rateBp = $result->rateBp;
                    $feeBp = $result->feeBp;
                    $cashbackLaari = $zeroed ? 0 : $result->cashbackLaari;
                    $feeLaari = $zeroed ? 0 : $result->feeLaari;
                } else {
                    // Lined credit: per-line §4 pricing; totals = SUM of the
                    // stored line integers, never recomputed on aggregates.
                    // Zeroed rows price without promotions and store their
                    // lines with zero money — the line snapshots still
                    // evidence the terms each line met, and the sums stay
                    // equal to the (zero) row totals.
                    $priced = $this->terms->resolveLines(
                        $merchant->id,
                        $branchId,
                        $lines,
                        $eligible,
                        $baseBp,
                        $customer->id,
                        $occurredAt,
                        consultPromotions: ! $zeroed,
                    );

                    $promotionId = $zeroed ? null : $priced->promotionId;
                    $rateBp = $priced->rowRateBp;
                    $feeBp = $priced->rowFeeBp;
                    $cashbackLaari = $zeroed ? 0 : $priced->cashbackTotal();
                    $feeLaari = $zeroed ? 0 : $priced->feeTotal();
                }

                $transaction = Transaction::query()->create([
                    'merchant_id' => $merchant->id,
                    'branch_id' => $branchId,
                    'customer_id' => $customer->id,
                    'promotion_id' => $promotionId,
                    'origin' => $origin,
                    'invoice_no' => $invoiceNo,
                    'idempotency_key' => $idempotencyKey,
                    'eligible_laari' => $eligible->value(),
                    'sale_laari' => $saleAmount?->value(),
                    'currency' => 'MVR',
                    'rate_bp' => $rateBp,
                    'fee_bp' => $feeBp,
                    'cashback_laari' => $cashbackLaari,
                    'fee_laari' => $feeLaari,
                    'fee_gst_laari' => 0,
                    'state' => TransactionState::Tracked,
                    'reason_code' => $reason,
                    // Permanent property of the row, not a transient
                    // qualifier: a backdated credit can never be reversed by
                    // the merchant or their vendor, and later transitions
                    // must not be able to erase that by rewriting
                    // reason_code (PLAN §1).
                    'backdated' => $backdated && ! $zeroed,
                    'occurred_at' => $occurredAt,
                    'received_at' => $now,
                ]);

                if ($priced !== null) {
                    foreach ($priced->lines as $line) {
                        TransactionLine::query()->create([
                            'transaction_id' => $transaction->id,
                            'product_category_id' => $line->categoryId,
                            'category_slug' => $line->slug,
                            'category_name_en' => $line->nameEn,
                            'amount_laari' => $line->amountLaari,
                            'currency' => 'MVR',
                            'effective_rate_bp' => $line->rateBp,
                            'fee_bp' => $line->feeBp,
                            'cashback_laari' => $zeroed ? 0 : $line->cashbackLaari,
                            'fee_laari' => $zeroed ? 0 : $line->feeLaari,
                            'priced_by' => $line->pricedBy,
                            'sort' => $line->sort,
                        ]);
                    }
                }

                $this->transitions->recordCreated($transaction, $actor, $reason);

                // A tiny eligible can round every §4 line to zero even above
                // the merchant's minimum — nothing accrued means nothing to post.
                $accruedLaari = $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;

                if (! $zeroed && $accruedLaari > 0) {
                    $this->postings->accrue(
                        $transaction->cashback_laari,
                        $transaction->fee_laari,
                        $transaction->fee_gst_laari,
                        referenceId: $transaction->id,
                    );
                }

                if ($zeroed) {
                    // Nothing accrued and nothing will ever validate — park the
                    // row in a terminal state immediately. Left tracked it would
                    // show the customer a Pending that can never confirm. No
                    // ledger reversal: the accrual never posted.
                    $this->transitions->reverse($transaction, Actor::system(), $reason);
                } elseif ($backdated) {
                    // PLAN §1 "Backdated credits" — no admin approval,
                    // immediately payable, merchant-irreversible. The old
                    // behaviour parked these on_hold, where they waited for a
                    // human who had nothing to decide: the sale's refund
                    // window closed long ago, so there is nothing left to
                    // validate. It goes straight to payable_unfunded with the
                    // 15-day clock starting NOW (makePayable stamps
                    // clock_start_at and due_at in the business timezone) and
                    // the row qualified `backdated_final`.
                    //
                    // The hop through awaiting_validation is the §6 state
                    // machine's only route from tracked to payable, and it is
                    // instantaneous — both events land in the same
                    // transaction, so the append-only history shows exactly
                    // what happened and why, without inventing a shortcut
                    // edge that only this path would ever use.
                    //
                    // on_hold now means fraud or dispute review, and nothing
                    // else.
                    $this->transitions->transition(
                        $transaction,
                        TransactionState::AwaitingValidation,
                        Actor::system(),
                        self::BACKDATED_REASON,
                        stampReasonOnRow: false,
                    );
                    $this->transitions->makePayable($transaction, Actor::system(), self::BACKDATED_REASON);
                } else {
                    // The event records WHY the hop happened; the row's
                    // reason_code stays null — a clean pending sale has no
                    // state qualifier (§9.2: reason_code null on creation).
                    $this->transitions->transition(
                        $transaction,
                        TransactionState::AwaitingValidation,
                        Actor::system(),
                        'auto_validation_window',
                        stampReasonOnRow: false,
                    );

                    // A window of ZERO means there is no window. The sweeper
                    // would release this row on its very next pass — its
                    // predicate is `occurred_at + 0 days <= now`, true the
                    // instant the sale lands — but it runs hourly, so the
                    // store would watch a sale it can never refund sit in the
                    // refund window for up to an hour, and the customer would
                    // see a Pending that was never pending on anything.
                    //
                    // Doing it here is not a shortcut past the state machine:
                    // it is the same two events the sweeper writes, in the
                    // same order, with the same actor and no reason — the
                    // history is indistinguishable from a sweep that happened
                    // to run a second later, which is exactly what it stands
                    // in for.
                    if ((int) $merchant->validation_window_days === 0) {
                        $this->transitions->makePayable($transaction, Actor::system());
                    }
                }

                // Tell the customer, unless nothing was earned. A reversed or
                // zero-value credit has nothing to announce, and announcing
                // it would be the platform's most confusing possible message.
                // The service defers to afterCommit, so a sale that fails to
                // commit never sends.
                if (! $zeroed && $transaction->cashback_laari > 0) {
                    $template = $this->notifications->template(NotificationTemplateKey::CashbackEarned);
                    $dhivehi = $template?->sendsDhivehi() ?? false;

                    $this->notifications->send(NotificationTemplateKey::CashbackEarned, $customer, [
                        'amount' => NotificationService::money((int) $transaction->cashback_laari, $dhivehi),
                        'store' => (string) ($dhivehi && $merchant->name_dv !== null ? $merchant->name_dv : $merchant->name),
                    ]);
                }

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) {
            throw DuplicateInvoiceException::for($merchant, $invoiceNo);
        }
    }

    /**
     * The per-sale override gate (PLAN §1). Answers the override in basis
     * points when it may be applied, and throws otherwise — BEFORE the DB
     * transaction opens, so a refusal writes nothing at all.
     *
     * Ceiling: the ACTIVE fee tier schedule, exactly like every other
     * rate-setting path (a standing rate, a promotion, a product category).
     * The override is a decision made NOW, so it is bounded by what the
     * platform can price NOW — not by the older, possibly narrower schedule
     * governing a backdated occurred_at. The fee for such a backdated
     * override falls back to the static §4 map (TermsResolver::baseFeeBp),
     * the same rescue category rates already use.
     *
     * Floor: the rate the sale would otherwise earn — the standing rate at
     * occurred_at, or the best live PUBLISHED promotion actually covering
     * this sale (branch scope and minimum purchase both matched, exactly as
     * TermsResolver matches them). That number is what the customer was
     * advertised, so it is the floor an override may never dip below. Note
     * the promotion counts here even if its per-customer cap is exhausted
     * for THIS customer: the advertised promise is a property of the offer,
     * not of one customer's remaining headroom.
     *
     * AN OVERRIDE ONLY BOOSTS, SO AN EQUAL ONE CHANGES NOTHING. When the
     * value is exactly the advertised rate the sale prices as if the field
     * had never been sent: the standing rate is returned and the ordinary
     * promotion walk runs. This is the whole difference between a boost and
     * a silent change of terms. A live promotion's rate is advertised
     * TOGETHER WITH ITS CAP (max_cashback_per_customer_laari), and taking
     * the promo rate as the base rate would make the promotion stop
     * boosting — TermsResolver breaks its candidate walk at
     * `rate_bp <= base` — so the sale would pay the promo rate with the
     * cap never consulted, the promotion never stamped, and the merchant's
     * own exposure bound gone. That is exactly what a vendor echoing
     * `active_promotion.cashback_rate_percent` from GET
     * /v1/merchants/me/rate back into the sale would do, on every sale.
     * Echoing the advertised number is now a no-op, which is what an
     * integrator means by it.
     *
     * A value STRICTLY ABOVE the advertised rate is a deliberate
     * instruction to pay more than the published offer, and is honoured as
     * one: the sale prices at the override, and the promotion — which pays
     * nothing here — neither stamps the row nor consumes any headroom, the
     * same rule TermsResolver already applies to a promotion that priced
     * nothing.
     *
     * @throws RateNotPricedException|RateBelowAdvertisedException
     */
    private function assertOverridable(
        Merchant $merchant,
        ?int $branchId,
        Laari $eligible,
        CarbonImmutable $occurredAt,
        int $standingBp,
        int $overrideBp,
    ): int {
        $this->schedules->assertPriced($overrideBp);

        $advertisedBp = max(
            $standingBp,
            (int) ($this->promotions
                ->candidatesAt($merchant->id, $branchId, $eligible->value(), $occurredAt)
                ->first()?->rate_bp ?? 0),
        );

        if ($overrideBp < $advertisedBp) {
            throw RateBelowAdvertisedException::for($overrideBp, $advertisedBp);
        }

        // Only a STRICT boost changes how the sale prices; an override equal
        // to the advertised rate prices exactly as no override at all, so
        // the promotion that advertised it still applies under its own cap.
        return $overrideBp > $advertisedBp ? $overrideBp : $standingBp;
    }

    /**
     * §5 sale-time rate resolution: the row effective at occurred_at, read
     * from the append-only history — never a column on merchants.
     */
    private function resolveRateBp(Merchant $merchant, CarbonImmutable $occurredAt): int
    {
        $rate = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->where('effective_from', '<=', $occurredAt)
            ->where(function ($query) use ($occurredAt) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $occurredAt);
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($rate === null) {
            throw NoEffectiveRateException::for($merchant, $occurredAt);
        }

        return $rate->rate_bp;
    }
}
