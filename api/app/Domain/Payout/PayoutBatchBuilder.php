<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Platform\PlatformConfig;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds one draft payout batch per calendar month. The cutoff is the 24th
 * at 23:59:59 in the business timezone (§13), converted to a UTC instant;
 * everything confirmed at or before it and not yet linked to a payout item
 * is a candidate, subject to the per-customer minimum.
 *
 * Rebuilding an existing draft is allowed by cancel + recreate only:
 * cancelDraft() releases the transaction links and retires the reference,
 * after which buildDraft() for the same period starts clean. There is no
 * in-place rebuild — a draft's items are never mutated.
 */
final readonly class PayoutBatchBuilder
{
    private const int CUTOFF_DAY = 24;

    public function __construct(
        private EligibilityQuery $eligibility,
        private PlatformConfig $config,
    ) {}

    public function buildDraft(int $periodYear, int $periodMonth, AdminUser $creator): PayoutBatch
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $cutoff = CarbonImmutable::create($periodYear, $periodMonth, self::CUTOFF_DAY, 23, 59, 59, $timezone)->utc();
        $periodStart = CarbonImmutable::create($periodYear, $periodMonth, 1, 0, 0, 0, $timezone);
        $reference = sprintf('PB-%04d-%02d', $periodYear, $periodMonth);

        // A batch built before the cutoff would silently miss every
        // confirmation still to come — refuse early builds outright.
        if ($cutoff->isAfter(CarbonImmutable::now('UTC'))) {
            throw CutoffInFutureException::for($reference, $cutoff);
        }

        return DB::transaction(function () use ($reference, $cutoff, $periodStart, $creator): PayoutBatch {
            $exists = PayoutBatch::query()
                ->where('reference', $reference)
                ->where('state', '!=', PayoutBatchState::Cancelled)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw DuplicatePayoutBatchException::for($reference);
            }

            $batch = PayoutBatch::query()->create([
                'reference' => $reference,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodStart->endOfMonth()->toDateString(),
                'cutoff_at' => $cutoff,
                'state' => PayoutBatchState::Draft,
                'created_by' => $creator->id,
            ]);

            $totalLaari = 0;
            $customerCount = 0;
            $excludedLaari = 0;
            $excludedCount = 0;

            // The per-customer minimum is the admin-managed min_payout_laari
            // setting (default MVR 100, §13).
            foreach ($this->eligibility->eligibleAt($cutoff, $this->config->minPayoutLaari()) as $eligible) {
                $customer = Customer::query()->findOrFail($eligible->customerId);

                // A payout needs somewhere to go: a customer over the minimum
                // but with incomplete bank details is skipped — their
                // transactions stay unlinked and carry forward — and the
                // skipped money is surfaced on the batch so admins can see
                // what is waiting on bank details.
                if (! $this->hasBankDetails($customer)) {
                    $excludedLaari += $eligible->amountLaari;
                    $excludedCount++;

                    continue;
                }

                $item = PayoutItem::query()->create([
                    'batch_id' => $batch->id,
                    'customer_id' => $customer->id,
                    'amount_laari' => $eligible->amountLaari,
                    // Bank details are snapshotted at build time — a later
                    // change on the customer never rewrites a built item.
                    'bank' => $customer->payout_bank,
                    'account' => $customer->payout_account,
                    'account_name' => $customer->payout_account_name,
                    'state' => PayoutItemState::Pending,
                ]);

                // Linking is not a state change — the transactions stay
                // confirmed — so no TransitionService event is due here. The
                // whereNull guard makes a concurrent build lose loudly
                // instead of double-including a transaction.
                $linked = Transaction::query()
                    ->whereIn('id', $eligible->transactionIds)
                    ->whereNull('payout_item_id')
                    ->update(['payout_item_id' => $item->id]);

                if ($linked !== count($eligible->transactionIds)) {
                    throw new RuntimeException(sprintf(
                        'Eligible transactions for customer #%d were claimed by a concurrent build — batch %s rolled back.',
                        $customer->id,
                        $batch->reference,
                    ));
                }

                $totalLaari += $eligible->amountLaari;
                $customerCount++;
            }

            // The batch total is the sum of stored item integers, never recomputed.
            $batch->forceFill([
                'total_laari' => $totalLaari,
                'customer_count' => $customerCount,
                'excluded_customer_count' => $excludedCount,
                'excluded_total_laari' => $excludedLaari,
            ])->save();

            return $batch;
        });
    }

    /**
     * All three §13 bank fields, present and non-blank.
     */
    private function hasBankDetails(Customer $customer): bool
    {
        foreach ([$customer->payout_bank, $customer->payout_account, $customer->payout_account_name] as $detail) {
            if ($detail === null || trim($detail) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Withdraws a draft: releases every transaction link so the amounts
     * re-enter the next build's eligibility, then retires the batch as
     * cancelled (kept for audit; the reference becomes reusable).
     */
    public function cancelDraft(PayoutBatch $batch): PayoutBatch
    {
        return DB::transaction(function () use ($batch): PayoutBatch {
            PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->first();
            $batch->refresh();

            if ($batch->state !== PayoutBatchState::Draft) {
                throw InvalidPayoutBatchStateException::cancel($batch);
            }

            Transaction::query()
                ->whereIn('payout_item_id', $batch->items()->select('id'))
                ->update(['payout_item_id' => null]);

            $batch->forceFill(['state' => PayoutBatchState::Cancelled])->save();

            return $batch;
        });
    }
}
