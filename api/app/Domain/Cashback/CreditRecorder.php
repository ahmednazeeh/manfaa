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
 */
final readonly class CreditRecorder
{
    /** Clock-skew allowance before an occurred_at counts as future-dated. */
    private const int FUTURE_SKEW_MINUTES = 5;

    /** Days past the merchant's validation window before a credit is stale. */
    private const int STALE_GRACE_DAYS = 3;

    public function __construct(
        private TransitionService $transitions,
        private Postings $postings,
        private CashbackCalculator $calculator,
    ) {}

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
        $result = $this->calculator->calculate($eligible, Rate::cashback($this->resolveRateBp($merchant, $occurredAt)));

        $ineligible = $ineligibleReason !== null;
        $belowMinimum = $eligible->value() < $merchant->min_eligible_laari;
        $stale = $occurredAt->isBefore($now->subDays($merchant->validation_window_days + self::STALE_GRACE_DAYS));

        // Nothing ever accrues on an ineligible or below-minimum sale, and
        // the row goes terminal immediately. Suspension outranks the minimum:
        // the reason the cashier sees must be the one that stops accrual
        // resuming (§7).
        $zeroed = $ineligible || $belowMinimum;
        $reason = $ineligible ? $ineligibleReason : ($belowMinimum ? 'below_minimum' : null);

        try {
            return DB::transaction(function () use ($merchant, $actor, $origin, $customer, $invoiceNo, $eligible, $saleAmount, $occurredAt, $now, $branchId, $idempotencyKey, $result, $zeroed, $reason, $stale): Transaction {
                $transaction = Transaction::query()->create([
                    'merchant_id' => $merchant->id,
                    'branch_id' => $branchId,
                    'customer_id' => $customer->id,
                    'origin' => $origin,
                    'invoice_no' => $invoiceNo,
                    'idempotency_key' => $idempotencyKey,
                    'eligible_laari' => $eligible->value(),
                    'sale_laari' => $saleAmount?->value(),
                    'currency' => 'MVR',
                    'rate_bp' => $result->rateBp,
                    'fee_bp' => $result->feeBp,
                    'cashback_laari' => $zeroed ? 0 : $result->cashbackLaari,
                    'fee_laari' => $zeroed ? 0 : $result->feeLaari,
                    'fee_gst_laari' => 0,
                    'state' => TransactionState::Tracked,
                    'reason_code' => $reason,
                    'occurred_at' => $occurredAt,
                    'received_at' => $now,
                ]);

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
                } elseif ($stale) {
                    $this->transitions->hold($transaction, Actor::system(), 'stale_timestamp');
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
