<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\Postings;
use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Applies the bank's result file to a processing batch, atomically:
 *
 * - batch: processing → sent when the import starts, then completed if every
 *   item is paid, partially_failed if any item failed, or left sent when the
 *   file only covered some items (§6 mapping documented on PayoutBatchState).
 * - paid item: item → paid, each linked transaction confirmed → paid through
 *   the TransitionService (actor system, reason payout_completed), and ONE
 *   payoutSent journal per item for the item's stored amount.
 * - failed item: item → failed with the reason; its transactions are
 *   unlinked (payout_item_id = null) and STAY confirmed, so they re-enter
 *   the next batch's eligibility untouched.
 *
 * Idempotent per item: a row whose item already carries that same final
 * result is a no-op — re-importing a file the bank sent twice changes no
 * state and posts no second journal. A row that contradicts an item's final
 * state still throws.
 *
 * Any bad row throws inside the transaction and the whole file is rejected.
 */
final readonly class ResultImporter
{
    public function __construct(
        private TransitionService $transitions,
        private Postings $postings,
    ) {}

    /**
     * @param  string  $csv  rows of item_id,status[paid|failed],reference?,failure_reason? — an item_id header row is skipped
     */
    public function importCsv(PayoutBatch $batch, string $csv): PayoutBatch
    {
        $rows = $this->parse($csv);

        return DB::transaction(function () use ($batch, $rows): PayoutBatch {
            PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->first();
            $batch->refresh();

            $importable = [
                PayoutBatchState::Processing,
                PayoutBatchState::Sent,
                PayoutBatchState::Completed,
                PayoutBatchState::PartiallyFailed,
            ];

            if (! in_array($batch->state, $importable, true)) {
                throw InvalidPayoutBatchStateException::import($batch);
            }

            if ($batch->state === PayoutBatchState::Processing) {
                $batch->forceFill(['state' => PayoutBatchState::Sent])->save();
            }

            $items = $batch->items()->get()->keyBy('id');

            foreach ($rows as $row) {
                /** @var PayoutItem|null $item */
                $item = $items->get($row['item_id']);

                if ($item === null) {
                    throw ImportRowException::unknownItem($row['item_id']);
                }

                if (! in_array($row['status'], ['paid', 'failed'], true)) {
                    throw ImportRowException::invalidStatus($item->id, $row['status']);
                }

                // Idempotency: the same final result applied twice is a no-op;
                // a contradicting result for a final item is refused.
                if ($item->state === PayoutItemState::Paid || $item->state === PayoutItemState::Failed) {
                    if ($item->state->value === $row['status']) {
                        continue;
                    }

                    throw ImportRowException::alreadyResolved($item);
                }

                if ($item->state !== PayoutItemState::Sent) {
                    throw ImportRowException::alreadyResolved($item);
                }

                match ($row['status']) {
                    'paid' => $this->applyPaid($item, $row['reference']),
                    'failed' => $this->applyFailed($item, $row['reference'], $row['failure_reason']),
                };
            }

            $total = $batch->items()->count();
            $paid = $batch->items()->where('state', PayoutItemState::Paid)->count();
            $failed = $batch->items()->where('state', PayoutItemState::Failed)->count();

            if ($failed > 0) {
                $batch->forceFill(['state' => PayoutBatchState::PartiallyFailed])->save();
            } elseif ($paid === $total) {
                $batch->forceFill(['state' => PayoutBatchState::Completed])->save();
            }

            return $batch->refresh();
        });
    }

    private function applyPaid(PayoutItem $item, ?string $reference): void
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

        // One journal per item, for the stored item integer — never a
        // per-transaction recomputation (§8: DR liability, CR settlement cash).
        $this->postings->payoutSent($item->amount_laari, referenceId: $item->id);
    }

    private function applyFailed(PayoutItem $item, ?string $reference, ?string $failureReason): void
    {
        $item->forceFill([
            'state' => PayoutItemState::Failed,
            'bank_reference' => $reference,
            'failure_reason' => $failureReason ?? 'unknown',
        ])->save();

        // The transactions stay confirmed — no state change, no event — but
        // lose their link, which is exactly what re-queues them next month.
        Transaction::query()
            ->where('payout_item_id', $item->id)
            ->update(['payout_item_id' => null]);
    }

    /**
     * @return list<array{item_id: int, status: string, reference: ?string, failure_reason: ?string}>
     */
    private function parse(string $csv): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($csv)) as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = str_getcsv($line);

            if ($index === 0 && strtolower(trim($fields[0] ?? '')) === 'item_id') {
                continue;
            }

            $itemId = trim($fields[0] ?? '');

            if ($itemId === '' || preg_match('/^\d+$/', $itemId) !== 1 || ! isset($fields[1])) {
                throw ImportRowException::malformed($index + 1);
            }

            $reference = trim($fields[2] ?? '');
            $failureReason = trim($fields[3] ?? '');

            $rows[] = [
                'item_id' => (int) $itemId,
                'status' => strtolower(trim($fields[1])),
                'reference' => $reference === '' ? null : $reference,
                'failure_reason' => $failureReason === '' ? null : $failureReason,
            ];
        }

        return $rows;
    }
}
