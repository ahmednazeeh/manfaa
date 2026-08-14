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
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The Phase 0 manual credit path — a merchant user keys a sale in by hand.
 * One call creates the transaction, writes its creation event, posts the
 * accrual journal, and moves it into the validation window, atomically.
 */
final readonly class ManualCreditService
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

    public function credit(
        Merchant $merchant,
        MerchantUser $actor,
        string $customerCode,
        string $invoiceNo,
        Laari $eligible,
        ?Laari $saleAmount,
        CarbonImmutable $occurredAt,
    ): Transaction {
        if ($merchant->status !== 'active') {
            throw MerchantNotActiveException::for($merchant);
        }

        $customer = Customer::query()->where('customer_code', $customerCode)->first()
            ?? throw CustomerNotFoundException::forCode($customerCode);

        $now = CarbonImmutable::now('UTC');
        $occurredAt = $occurredAt->utc();

        if ($occurredAt->isAfter($now->addMinutes(self::FUTURE_SKEW_MINUTES))) {
            throw FutureDatedTransactionException::at($occurredAt);
        }

        // Rate and fee are frozen at occurred_at even when the cashback is
        // zeroed below the minimum — the row must evidence the terms it met.
        $result = $this->calculator->calculate($eligible, Rate::cashback($this->resolveRateBp($merchant, $occurredAt)));

        $belowMinimum = $eligible->value() < $merchant->min_eligible_laari;
        $stale = $occurredAt->isBefore($now->subDays($merchant->validation_window_days + self::STALE_GRACE_DAYS));

        try {
            return DB::transaction(function () use ($merchant, $actor, $customer, $invoiceNo, $eligible, $saleAmount, $occurredAt, $now, $result, $belowMinimum, $stale): Transaction {
                $transaction = Transaction::query()->create([
                    'merchant_id' => $merchant->id,
                    'customer_id' => $customer->id,
                    'origin' => 'manual',
                    'invoice_no' => $invoiceNo,
                    'eligible_laari' => $eligible->value(),
                    'sale_laari' => $saleAmount?->value(),
                    'currency' => 'MVR',
                    'rate_bp' => $result->rateBp,
                    'fee_bp' => $result->feeBp,
                    'cashback_laari' => $belowMinimum ? 0 : $result->cashbackLaari,
                    'fee_laari' => $belowMinimum ? 0 : $result->feeLaari,
                    'fee_gst_laari' => 0,
                    'state' => TransactionState::Tracked,
                    'reason_code' => $belowMinimum ? 'below_minimum' : null,
                    'occurred_at' => $occurredAt,
                    'received_at' => $now,
                ]);

                $this->transitions->recordCreated(
                    $transaction,
                    Actor::merchantUser($actor->id),
                    $belowMinimum ? 'below_minimum' : null,
                );

                // A tiny eligible can round every §4 line to zero even above
                // the merchant's minimum — nothing accrued means nothing to post.
                $accruedLaari = $transaction->cashback_laari + $transaction->fee_laari + $transaction->fee_gst_laari;

                if (! $belowMinimum && $accruedLaari > 0) {
                    $this->postings->accrue(
                        $transaction->cashback_laari,
                        $transaction->fee_laari,
                        $transaction->fee_gst_laari,
                        referenceId: $transaction->id,
                    );
                }

                if ($stale) {
                    $this->transitions->hold($transaction, Actor::system(), 'stale_timestamp');
                } elseif (! $belowMinimum) {
                    $this->transitions->transition(
                        $transaction,
                        TransactionState::AwaitingValidation,
                        Actor::system(),
                        'auto_validation_window',
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
