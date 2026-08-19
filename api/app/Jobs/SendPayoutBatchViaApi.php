<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\BatchApiSender;
use App\Domain\Transfers\BatchNotSendableException;
use App\Domain\Transfers\TransferProfileRef;
use App\Models\PayoutBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pays a customer cashback batch through the bank API, off the web request —
 * a hundred live bank calls is not something an admin should hold a browser
 * tab open for.
 */
class SendPayoutBatchViaApi implements ShouldQueue
{
    use Queueable;

    /**
     * ONE attempt, and a long window to finish in.
     *
     * A queue-level retry would re-run the whole pass, which is the one
     * thing the owner said not to do. It would in fact be SAFE — every row
     * carries its own internal_ref and the upstream deduplicates — but safe
     * is not the same as wanted: rows deliberately left pending for a human
     * would be thrown at the bank again on every retry.
     */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        private readonly int $batchId,
        private readonly ?int $profileId = null,
    ) {}

    public function handle(BatchApiSender $sender): void
    {
        $batch = PayoutBatch::query()->find($this->batchId);

        if ($batch === null) {
            return;
        }

        try {
            $sender->sendCustomerBatch($batch, TransferProfileRef::resolve($this->profileId));
        } catch (BatchNotSendableException $e) {
            // Someone moved the batch between queueing and running. Not an
            // error worth failing the job over — the batch is simply not
            // ours to send any more.
            Log::warning('Payout batch could not be sent', [
                'batch' => $batch->reference,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
