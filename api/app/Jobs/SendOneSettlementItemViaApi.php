<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\TransferClient;
use App\Domain\Transfers\TransferOutcome;
use App\Models\MerchantPayoutItem;
use App\Models\TransferProfile;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** One settlement row, off the web request. Same reasons as its customer twin. */
class SendOneSettlementItemViaApi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly int $itemId,
        private readonly int $profileId,
        private readonly string $remarks,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('settlement-item:'.$this->itemId))
                ->dontRelease()
                ->expireAfter(400),
        ];
    }

    public function handle(TransferClient $client): void
    {
        $item = MerchantPayoutItem::query()->find($this->itemId);
        $profile = TransferProfile::query()->find($this->profileId);

        // Claimed as `processing` by the controller before this was queued,
        // so a second press is refused at the door rather than here.
        if ($item === null || $profile === null || (string) $item->state !== 'processing') {
            return;
        }

        $result = $client->send(
            $profile,
            account: (string) $item->account,
            amountLaari: (int) $item->amount_laari,
            // The SAME key every attempt — the whole of what makes a retry
            // safe rather than a second payment.
            internalRef: (string) $item->internal_ref,
            beneficiaryName: $item->account_name,
            remarks: $this->remarks,
        );

        $item->forceFill(match ($result->outcome) {
            TransferOutcome::Sent => [
                'state' => 'sent',
                'trx_id' => $result->trxId,
                'paid_at' => CarbonImmutable::now(),
                'error_code' => null,
                'failure_reason' => null,
            ],
            TransferOutcome::PendingApproval => [
                'state' => 'pending_approval',
                // Never as trx_id: a queue record is not a bank reference.
                'approval_id' => $result->approvalId,
                'error_code' => null,
                'failure_reason' => $result->message,
            ],
            default => [
                'state' => 'failed',
                'error_code' => $result->errorCode,
                'failure_reason' => $result->message,
            ],
        })->save();
    }
}
