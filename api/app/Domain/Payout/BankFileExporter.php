<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Renders the bank file for an approved batch and advances it to processing
 * (§6), stamping exported_at and marking every item sent.
 *
 * The FIRST export is the state transition. A re-export is allowed while
 * the batch is still processing and every item still sits at sent — the
 * items are frozen in that window, so the file re-renders with identical
 * content and a download that died mid-transfer never strands the batch.
 * Identical content, not identical bytes: an xlsx is a zip, and a zip
 * carries timestamps of its own. The moment a result touches any item,
 * export closes: from there a fresh file would diverge from the one the
 * bank is acting on.
 */
final readonly class BankFileExporter
{
    public function __construct(private BankFileFormatter $formatter = new TransferSheetExporter) {}

    public function export(PayoutBatch $batch): string
    {
        return DB::transaction(function () use ($batch): string {
            PayoutBatch::query()->whereKey($batch->getKey())->lockForUpdate()->first();
            $batch->refresh();

            if ($batch->state === PayoutBatchState::Processing
                && ! $batch->items()->where('state', '!=', PayoutItemState::Sent)->exists()) {
                // Recovery re-export: identical content, no state change.
                return $this->formatter->format($batch);
            }

            if ($batch->state !== PayoutBatchState::Approved) {
                throw InvalidPayoutBatchStateException::export($batch);
            }

            $content = $this->formatter->format($batch);

            $batch->items()
                ->where('state', PayoutItemState::Pending)
                ->update(['state' => PayoutItemState::Sent]);

            $batch->forceFill([
                'state' => PayoutBatchState::Processing,
                'exported_at' => CarbonImmutable::now('UTC'),
            ])->save();

            return $content;
        });
    }
}
