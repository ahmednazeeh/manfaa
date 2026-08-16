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
 * Builds one draft payout batch as of a cutoff the admin picks, so a run can
 * be weekly, fortnightly, or whatever the week needs. Everything confirmed at
 * or before that instant and not yet linked to a payout item is a candidate,
 * subject to the per-customer minimum. There is deliberately no lower bound
 * on eligibility: whatever an earlier batch left behind — a customer under
 * the minimum, a customer without bank details, a reward confirmed after the
 * last cutoff — is swept into the next one.
 *
 * Rebuilding an existing draft is allowed by cancel + recreate only:
 * cancelDraft() releases the transaction links and retires the reference,
 * after which buildDraft() for the same cutoff date starts clean. There is no
 * in-place rebuild — a draft's items are never mutated.
 */
final readonly class PayoutBatchBuilder
{
    public function __construct(
        private EligibilityQuery $eligibility,
        private PlatformConfig $config,
    ) {}

    public function buildDraft(CarbonImmutable $cutoff, AdminUser $creator): PayoutBatch
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        // Compared and stored in UTC, whatever the caller handed over: a query
        // binding carrying a +05:00 instant is formatted without its offset and
        // read back as UTC, five hours from where it belongs.
        $cutoff = $cutoff->utc();
        // Reference and period name the business day the batch was taken as
        // of; the same instant in UTC can land on the day either side of it.
        $businessCutoff = $cutoff->setTimezone($timezone);
        $reference = 'PB-'.$businessCutoff->format('Ymd');

        // A batch built before the cutoff would silently miss every
        // confirmation still to come — refuse early builds outright.
        if ($cutoff->isAfter(CarbonImmutable::now('UTC'))) {
            throw CutoffInFutureException::for($reference, $cutoff);
        }

        $periodStart = $this->periodStart($cutoff, $timezone);

        return DB::transaction(function () use ($reference, $cutoff, $businessCutoff, $periodStart, $creator): PayoutBatch {
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
                'period_start' => $periodStart,
                'period_end' => $businessCutoff->toDateString(),
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
                    'idempotency_key' => $this->mintIdempotencyKey(),
                    // Who the transfer is for and where it goes are both
                    // snapshotted at build time — a later change on the
                    // customer never rewrites a built item, and the sheet
                    // re-exports word for word what the bank already has.
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
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
     * Where the batch's period is shown as starting: the cutoff of the last
     * batch that still counts, because with a rolling cutoff the honest
     * period is "since the last run". Display only — eligibility itself is
     * open-ended below, so nothing falls between two periods.
     *
     * The first batch ever has no predecessor and genuinely does reach back
     * to the beginning, so it opens at the oldest confirmation on record
     * rather than at its own cutoff: a zero-length period on the one batch
     * whose reach is longest would read as a bug. With nothing confirmed at
     * all there is no earlier date to name, and the cutoff stands.
     */
    private function periodStart(CarbonImmutable $cutoff, string $timezone): string
    {
        $previousCutoff = PayoutBatch::query()
            ->where('state', '!=', PayoutBatchState::Cancelled)
            ->where('cutoff_at', '<', $cutoff)
            ->orderByDesc('cutoff_at')
            ->value('cutoff_at');

        $start = $previousCutoff ?? $this->eligibility->earliestConfirmationAt() ?? $cutoff;

        return $start->setTimezone($timezone)->toDateString();
    }

    /**
     * MNF plus a six-digit sequence value. Minted here rather than derived at
     * export time because a key that changes between two exports of the same
     * batch is not an idempotency key — it is what the filled sheet is
     * matched on when it comes back.
     */
    private function mintIdempotencyKey(): string
    {
        $next = DB::selectOne("select nextval('payout_items_idempotency_key_seq') as value");

        return sprintf('MNF%06d', (int) $next->value);
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
