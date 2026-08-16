<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\Postings;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplateKey;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use App\Models\Transaction;

/**
 * What a known transfer outcome does to the money — the single path from a
 * result to the ledger, whether the outcome arrived in an uploaded transfer
 * sheet or an admin recorded it by hand on the batch page. A second posting
 * path would be a second version of the truth.
 *
 * - paid item: item → paid, each linked transaction confirmed → paid through
 *   the TransitionService (actor system, reason payout_completed), and ONE
 *   payoutSent journal per item for the item's stored amount.
 * - failed item: item → failed with the reason; its transactions are
 *   unlinked (payout_item_id = null) and STAY confirmed, so they re-enter
 *   the next batch's eligibility untouched.
 *
 * Callers own the database transaction and the batch lock; nothing here
 * opens either, so a bad row anywhere in a pass rolls the whole pass back.
 */
final readonly class ItemResultService
{
    /**
     * The batch states in which an outcome can be recorded: the file has to
     * be with the bank before the bank can have paid anything.
     */
    private const array ACCEPTS_RESULTS = [
        PayoutBatchState::Processing,
        PayoutBatchState::Sent,
        PayoutBatchState::Completed,
        PayoutBatchState::PartiallyFailed,
    ];

    public function __construct(
        private TransitionService $transitions,
        private Postings $postings,
        private NotificationService $notifications,
    ) {}

    public function accepts(PayoutBatch $batch): bool
    {
        return in_array($batch->state, self::ACCEPTS_RESULTS, true);
    }

    /**
     * Opens a pass of results. An outcome arriving is proof the exported file
     * reached the bank, so a batch still sitting at processing moves to sent.
     */
    public function begin(PayoutBatch $batch): void
    {
        if ($batch->state === PayoutBatchState::Processing) {
            $batch->forceFill(['state' => PayoutBatchState::Sent])->save();
        }
    }

    public function applyPaid(PayoutItem $item, ?string $reference): void
    {
        $item->forceFill([
            'state' => PayoutItemState::Paid,
            'bank_reference' => $reference,
        ])->save();

        foreach (Transaction::query()->where('payout_item_id', $item->id)->orderBy('id')->get() as $transaction) {
            $this->transitions->transition(
                $transaction,
                TransactionState::Paid,
                Actor::system(),
                'payout_completed',
            );
        }

        // Tell them the money is coming. After the ledger work, and
        // deferred to afterCommit inside the service, so a customer is never
        // told about a payout that then rolls back.
        $customer = $item->customer;

        if ($customer !== null) {
            $template = $this->notifications->template(NotificationTemplateKey::PayoutPaid);

            $this->notifications->send(NotificationTemplateKey::PayoutPaid, $customer, [
                'amount' => NotificationService::money(
                    (int) $item->amount_laari,
                    $template?->sendsDhivehi() ?? false,
                ),
                'reference' => (string) ($reference ?? ''),
            ]);
        }

        // One journal per item, for the stored item integer — never a
        // per-transaction recomputation (§8: DR liability, CR settlement cash).
        $this->postings->payoutSent($item->amount_laari, referenceId: $item->id);
    }

    public function applyFailed(PayoutItem $item, ?string $reference, ?string $failureReason): void
    {
        $item->forceFill([
            'state' => PayoutItemState::Failed,
            'bank_reference' => $reference,
            'failure_reason' => $failureReason ?? 'unknown',
        ])->save();

        // The transactions stay confirmed — no state change, no event — but
        // lose their link, which is exactly what re-queues them into the
        // next batch.
        Transaction::query()
            ->where('payout_item_id', $item->id)
            ->update(['payout_item_id' => null]);
    }

    /**
     * Closes a pass: any failure leaves the batch partially failed, a clean
     * sweep completes it, and a batch the pass only half covered stays where
     * it is, waiting for the rest of the outcomes.
     */
    public function conclude(PayoutBatch $batch): void
    {
        $total = $batch->items()->count();
        $paid = $batch->items()->where('state', PayoutItemState::Paid)->count();
        $failed = $batch->items()->where('state', PayoutItemState::Failed)->count();

        if ($failed > 0) {
            $batch->forceFill(['state' => PayoutBatchState::PartiallyFailed])->save();
        } elseif ($paid === $total) {
            $batch->forceFill(['state' => PayoutBatchState::Completed])->save();
        }
    }
}
