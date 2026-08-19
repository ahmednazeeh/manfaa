<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\BatchApiSender;
use App\Domain\Transfers\BatchNotSendableException;
use App\Domain\Transfers\TransferProfileRef;
use App\Models\MerchantPayoutBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/** The same pass, for what the platform owes shops. */
class SendMerchantPayoutBatchViaApi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Four hours. A single transfer can take two minutes, so an hour covered
     * barely thirty rows — a real batch would have been killed mid-sweep,
     * leaving the rest unsent with nothing saying why.
     *
     * Being cut off is survivable rather than catastrophic — sent rows are
     * recorded, unsent ones stay pending, and pressing Send again resumes on
     * the same idempotency keys — but it should not be the normal outcome of
     * a large batch.
     */
    public int $timeout = 14400;

    /**
     * Never two sweeps of the same batch at once.
     *
     * This is not belt-and-braces, it is load-bearing. The Redis queue's
     * `retry_after` is 90 seconds and a sweep is one live bank call per row,
     * so any batch of real size runs past it — at which point Redis hands
     * the SAME job to a second worker while the first is still going. The
     * idempotency keys mean nobody would be paid twice, but two workers
     * racing over one batch is not a thing to leave running on trust.
     *
     * `dontRelease()` because the duplicate is genuinely redundant: the
     * original is still sweeping, and re-queueing would just spin. The lock
     * expires well past the job timeout so a killed worker cannot leave a
     * batch permanently unsendable.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('settlement-run:'.$this->batchId))
                ->dontRelease()
                // Past the job timeout, so a killed worker cannot leave a
                // batch permanently unsendable.
                ->expireAfter(14500),
        ];
    }

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
