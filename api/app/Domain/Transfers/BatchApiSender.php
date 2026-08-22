<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Domain\Payout\ItemResultService;
use App\Domain\Payout\PayoutBatchState;
use App\Domain\Payout\PayoutItemState;
use App\Models\MerchantPayoutBatch;
use App\Models\MerchantPayoutItem;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paying a whole batch through the bank API — the third road to the bank,
 * beside the exported sheet and the per-row Mark paid
 * (owner requirement 2026-08-19).
 *
 * Three rules decide everything here:
 *
 * 1. A REFUSAL IS NOT RETRIED. "When api returns transfer failed, dont retry
 *    and instead move on to next transfer." A batch that stops at the first
 *    bad account number pays nobody, and re-sending into an upstream that
 *    has already said no is how a pass becomes a flood.
 *
 * 2. EVERY SEND CARRIES ITS OWN internal_ref, stored on the row long before
 *    this job existed. A worker that dies mid-batch and is re-run cannot pay
 *    anyone twice: the upstream recognises the repeat and answers with what
 *    the first attempt did. This is the whole reason the column exists.
 *
 * 3. AMBIGUITY IS LEFT ALONE. A refusal that proves no debit (bad account,
 *    bad amount) marks the row failed, which re-queues its transactions. A
 *    refusal we cannot read that way — a timeout, a 500 — marks nothing. The
 *    money may have moved. A person looks.
 *
 * Outcomes reach the ledger through {@see ItemResultService}, the same path
 * an uploaded sheet uses. A second posting path would be a second version of
 * the truth.
 */
final readonly class BatchApiSender
{
    /** Batch states in which a pass may run. */
    private const array CUSTOMER_SENDABLE = [
        PayoutBatchState::Approved,
        PayoutBatchState::Processing,
        PayoutBatchState::Sent,
        PayoutBatchState::PartiallyFailed,
    ];

    private const array MERCHANT_SENDABLE = ['approved', 'processing'];

    public function __construct(
        private TransferClient $client,
        private ItemResultService $results,
    ) {}

    /**
     * A pass over one customer cashback batch.
     *
     * The batch is NOT held under a lock for the length of the pass — that
     * could be minutes of live HTTP against a bank, and a transaction open
     * that long is a transaction that will be killed at the worst moment.
     * Each item is settled in its own short transaction instead.
     */
    public function sendCustomerBatch(PayoutBatch $batch, TransferProfileRef $ref): BatchSendSummary
    {
        $batch = $batch->refresh();

        if (! in_array($batch->state, self::CUSTOMER_SENDABLE, true)) {
            throw new BatchNotSendableException(
                'A '.$batch->state->label().' batch cannot be sent to the bank.'
            );
        }

        if ($batch->state === PayoutBatchState::Approved) {
            $batch->forceFill([
                'state' => PayoutBatchState::Processing,
                // NOT exported_at — no file was made. The batch page reads
                // these two apart so it can say which road this took.
                'api_sent_at' => CarbonImmutable::now(),
            ])->save();
        } elseif ($batch->api_sent_at === null) {
            $batch->forceFill(['api_sent_at' => CarbonImmutable::now()])->save();
        }

        $summary = new BatchSendSummary;

        foreach ($batch->items()->orderBy('id')->get() as $item) {
            /** @var PayoutItem $item */
            if ($item->state !== PayoutItemState::Pending) {
                // Already sent, paid, failed or parked. A sweep passes over
                // what it does not own.
                $summary = $summary->skip();

                continue;
            }

            $key = trim((string) $item->idempotency_key);

            if ($key === '') {
                // Without a key we cannot promise this is safe to repeat, and
                // a payment we cannot promise that about is not one we send.
                Log::warning('Payout item has no idempotency key', ['item' => $item->id]);
                $summary = $summary->skip();

                continue;
            }

            $result = $this->client->send(
                // One profile pays everybody. The payee's own bank is
                // recorded on the row but does not choose the sender: every
                // payout leaves from MIB.
                $ref->profile,
                account: (string) $item->account,
                amountLaari: (int) $item->amount_laari,
                internalRef: $key,
                beneficiaryName: $item->account_name,
                remarks: 'Manfaa cashback payout',
            );

            $this->applyCustomer($item, $result);
            $summary = $summary->with($result->outcome);

            // On to the next transfer. No retry, by design.
        }

        DB::transaction(function () use ($batch): void {
            /** @var PayoutBatch $locked */
            $locked = PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $this->results->conclude($locked);
        });

        Log::info('Payout batch swept through the bank API', [
            'batch' => $batch->reference,
        ] + $summary->toArray());

        return $summary;
    }

    private function applyCustomer(PayoutItem $item, TransferResult $result): void
    {
        DB::transaction(function () use ($item, $result): void {
            /** @var PayoutItem $locked */
            $locked = PayoutItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state !== PayoutItemState::Pending) {
                return;
            }

            $locked->forceFill([
                'attempts' => (int) $locked->attempts + 1,
                'error_code' => $result->errorCode,
                'approval_id' => $result->approvalId,
            ])->save();

            // One line per bank call, because reconciling a payment after
            // the fact needs to know WHICH answer produced it. `duplicate`
            // is the load-bearing field: it separates "the upstream
            // recognised our reference and gave back the payment it already
            // made" from "a second payment was made just now" — and those
            // look identical in the row afterwards.
            Log::info('Payout item answered by the bank', [
                'item' => $locked->id,
                'internal_ref' => $locked->idempotency_key,
                'outcome' => $result->outcome->name,
                'duplicate' => $result->wasDuplicate,
                'trx_id' => $result->trxId,
                'approval_id' => $result->approvalId,
                'error_code' => $result->errorCode,
            ]);

            match ($result->outcome) {
                // The money moved. Straight down the ledger path a filled
                // sheet uses.
                TransferOutcome::Sent => $this->results->applyPaid($locked, $result->trxId),

                // Parked on dual control. Marked `sent` so no later pass
                // picks it up — re-sending a parked transfer pays twice —
                // and the approvals-queue id is kept for whoever chases it.
                TransferOutcome::PendingApproval => $locked->forceFill([
                    'state' => PayoutItemState::Sent,
                    'failure_reason' => 'Waiting for a second approver'
                        .($result->approvalId !== null && $result->approvalId !== ''
                            ? ' ('.$result->approvalId.')'
                            : ''),
                ])->save(),

                // Proven not to have debited. Failing it here is what puts
                // the customer's cashback back into the next batch.
                TransferOutcome::FailedRetryable => $this->results->applyFailed(
                    $locked,
                    null,
                    $result->message ?? 'The bank refused the transfer.',
                ),

                // We cannot prove nothing left the account. Left PENDING on
                // purpose: marking it failed would re-queue money that may
                // already be gone.
                TransferOutcome::FailedNeedsReview => $locked->forceFill([
                    'failure_reason' => 'Needs review: '.($result->message ?? 'the bank did not answer clearly.'),
                ])->save(),
            };
        });
    }

    /**
     * A pass over one merchant settlement batch. Same three rules; the
     * merchant rows keep their own outcome columns rather than going through
     * the cashback ledger, because a settlement is a payment of an invoice,
     * not a cashback transaction changing state.
     */
    public function sendMerchantBatch(MerchantPayoutBatch $batch, TransferProfileRef $ref): BatchSendSummary
    {
        $batch = $batch->refresh();

        if (! in_array((string) $batch->state, self::MERCHANT_SENDABLE, true)) {
            throw new BatchNotSendableException(
                'A '.str_replace('_', ' ', (string) $batch->state).' settlement run cannot be sent to the bank.'
            );
        }

        $batch->forceFill([
            'state' => 'processing',
            'api_sent_at' => $batch->api_sent_at ?? CarbonImmutable::now(),
        ])->save();

        $summary = new BatchSendSummary;

        foreach ($batch->items()->orderBy('id')->get() as $item) {
            /** @var MerchantPayoutItem $item */
            if ((string) $item->state !== 'pending') {
                $summary = $summary->skip();

                continue;
            }

            $key = trim((string) $item->internal_ref);

            if ($key === '') {
                Log::warning('Settlement item has no internal ref', ['item' => $item->id]);
                $summary = $summary->skip();

                continue;
            }

            $result = $this->client->send(
                $ref->profile,
                account: (string) $item->account,
                amountLaari: (int) $item->amount_laari,
                internalRef: $key,
                beneficiaryName: $item->account_name,
                remarks: 'Manfaa merchant settlement',
            );

            $this->applyMerchant($item, $result);
            $summary = $summary->with($result->outcome);
        }

        $batch->forceFill([
            'state' => $batch->items()->where('state', '!=', 'sent')->exists()
                ? 'processing'
                : 'completed',
        ])->save();

        Log::info('Settlement run swept through the bank API', [
            'batch' => $batch->reference,
        ] + $summary->toArray());

        return $summary;
    }

    private function applyMerchant(MerchantPayoutItem $item, TransferResult $result): void
    {
        DB::transaction(function () use ($item, $result): void {
            /** @var MerchantPayoutItem $locked */
            $locked = MerchantPayoutItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $locked->state !== 'pending') {
                return;
            }

            $common = [
                'attempts' => (int) $locked->attempts + 1,
                'error_code' => $result->errorCode,
                'approval_id' => $result->approvalId,
            ];

            $locked->forceFill($common + match ($result->outcome) {
                TransferOutcome::Sent => [
                    'state' => 'sent',
                    'trx_id' => $result->trxId,
                    'paid_at' => CarbonImmutable::now(),
                    'failure_reason' => null,
                ],
                // Its own state, not `sent`: the shop has not been paid yet,
                // and the settlements screen must not say it has.
                TransferOutcome::PendingApproval => [
                    'state' => 'pending_approval',
                    'failure_reason' => 'Waiting for a second approver.',
                ],
                TransferOutcome::FailedRetryable => [
                    'state' => 'failed',
                    'failure_reason' => $result->message ?? 'The bank refused the transfer.',
                ],
                // Stays pending. See applyCustomer().
                TransferOutcome::FailedNeedsReview => [
                    'failure_reason' => 'Needs review: '.($result->message ?? 'the bank did not answer clearly.'),
                ],
            })->save();
        });
    }
}
