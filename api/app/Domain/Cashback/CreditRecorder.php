<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Domain\Ledger\Postings;
use App\Domain\Money\CashbackCalculator;
use App\Domain\Money\Laari;
use App\Domain\Money\Rate;
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

    public function __construct(
        private TransitionService $transitions,
        private Postings $postings,
        private CashbackCalculator $calculator,
        private TermsResolver $terms,
    ) {}

    /**
     * @param  list<LineInput>|null  $lines  parsed line splits (LineSetParser) — null for a single-rate credit
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
        $backdated = $occurredAt->isBefore($now->subDays($merchant->validation_window_days + self::STALE_GRACE_DAYS));

        // Nothing ever accrues on an ineligible or below-minimum sale, and
        // the row goes terminal immediately. Suspension outranks the minimum:
        // the reason the cashier sees must be the one that stops accrual
        // resuming (§7).
        $zeroed = $ineligible || $belowMinimum;
        $reason = $ineligible ? $ineligibleReason : ($belowMinimum ? 'below_minimum' : null);

        try {
            return DB::transaction(function () use ($merchant, $actor, $origin, $customer, $invoiceNo, $eligible, $saleAmount, $occurredAt, $now, $branchId, $idempotencyKey, $standingBp, $zeroed, $reason, $backdated, $lines): Transaction {
                $priced = null;

                if ($lines === null) {
                    // Promo-aware terms at occurred_at (PLAN §12 Phase 3): only
                    // rows that actually accrue consult promotions — a zeroed row
                    // freezes the standing terms it failed against.
                    [$result, $promotionId] = $zeroed
                        ? [$this->calculator->calculate($eligible, Rate::cashback($standingBp)), null]
                        : $this->terms->resolve($merchant->id, $branchId, $eligible, $standingBp, $customer->id, $occurredAt);

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
                        $standingBp,
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
                }

                return $transaction;
            });
        } catch (UniqueConstraintViolationException) {
            throw DuplicateInvoiceException::for($merchant, $invoiceNo);
        }
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
