<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use Illuminate\Support\Facades\DB;

/**
 * Records transfer outcomes by hand, for the batch page's per-row Mark paid
 * and Mark failed and its Settle all. The filled sheet is the usual road in;
 * this is the one for a transfer that went out on its own, for the rejection
 * the sheet has no column for, and for a bulk transfer the bank confirmed
 * under a single reference.
 *
 * Only a pending or sent item can take an outcome, and only once the batch's
 * file has been exported — before that there is no transfer to have an
 * outcome. Everything reaches the ledger through ItemResultService, the same
 * way an uploaded sheet does.
 */
final readonly class PayoutItemSettler
{
    public function __construct(private ItemResultService $results) {}

    public function settleOne(PayoutItem $item, string $reference): PayoutItem
    {
        return DB::transaction(function () use ($item, $reference): PayoutItem {
            $batch = $this->open($item);

            $this->results->applyPaid($item, $reference);
            $this->results->conclude($batch);

            return $item->refresh();
        });
    }

    public function failOne(PayoutItem $item, string $reason): PayoutItem
    {
        return DB::transaction(function () use ($item, $reason): PayoutItem {
            $batch = $this->open($item);

            // No bank reference: a transfer that never went through has none,
            // and inventing one would put a fiction in the audit trail.
            $this->results->applyFailed($item, null, $reason);
            $this->results->conclude($batch);

            return $item->refresh();
        });
    }

    /**
     * One bank transfer covering many payees settles under one reference, so
     * the reference given lands on every item still waiting for an outcome.
     * Items already paid or failed are passed over rather than refused —
     * settle-all is a sweep of what is left, not a claim about any one row.
     */
    public function settleAll(PayoutBatch $batch, string $reference): PayoutBatch
    {
        return DB::transaction(function () use ($batch, $reference): PayoutBatch {
            /** @var PayoutBatch $locked */
            $locked = PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->results->accepts($locked)) {
                throw InvalidPayoutBatchStateException::settle($locked);
            }

            $this->results->begin($locked);

            $outstanding = $locked->items()
                ->whereIn('state', [PayoutItemState::Pending, PayoutItemState::Sent])
                ->orderBy('id')
                ->get();

            foreach ($outstanding as $item) {
                $this->results->applyPaid($item, $reference);
            }

            $this->results->conclude($locked);

            return $locked->refresh();
        });
    }

    /**
     * Takes the batch lock the item hangs off, refreshes the item under it,
     * and checks both are in a state that can take an outcome. Returns the
     * locked batch so the caller can close the pass on it.
     */
    private function open(PayoutItem $item): PayoutBatch
    {
        /** @var PayoutBatch $batch */
        $batch = PayoutBatch::query()->whereKey($item->batch_id)->lockForUpdate()->firstOrFail();
        $item->refresh();

        if (! $this->results->accepts($batch)) {
            throw InvalidPayoutBatchStateException::settle($batch);
        }

        if ($item->state !== PayoutItemState::Pending && $item->state !== PayoutItemState::Sent) {
            throw InvalidPayoutItemStateException::for($item);
        }

        $this->results->begin($batch);

        return $batch;
    }
}
