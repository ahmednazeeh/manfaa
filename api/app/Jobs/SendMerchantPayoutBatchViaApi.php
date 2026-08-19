<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\BatchApiSender;
use App\Domain\Transfers\BatchNotSendableException;
use App\Domain\Transfers\TransferProfileRef;
use App\Models\MerchantPayoutBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** The same pass, for what the platform owes shops. */
class SendMerchantPayoutBatchViaApi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        private readonly int $batchId,
        private readonly ?int $profileId = null,
    ) {}

    public function handle(BatchApiSender $sender): void
    {
        $batch = MerchantPayoutBatch::query()->find($this->batchId);

        if ($batch === null) {
            return;
        }

        try {
            $sender->sendMerchantBatch($batch, TransferProfileRef::resolve($this->profileId));
        } catch (BatchNotSendableException $e) {
            Log::warning('Settlement run could not be sent', [
                'batch' => $batch->reference,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
