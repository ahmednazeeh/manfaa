<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Customers\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One customer notification, off the money path.
 *
 * Queued rather than sent inline because the provider is a network call
 * standing between a merchant pressing Credit and the till answering. A
 * payout run marking two hundred items paid must not become two hundred
 * HTTP round trips inside a database transaction.
 *
 * Retries three times and then gives up quietly. A notification is not a
 * ledger entry: the money moved whatever the SMS did, and a message about a
 * payout that arrives two days late is worse than one that never arrives.
 * The failure is logged for operations; the customer can always see the
 * truth in the app.
 */
class SendCustomerSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** 1m, then 5m — a provider blip recovers inside that; an outage does not. */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly string $phone,
        private readonly string $body,
        private readonly string $templateKey,
        private readonly int $customerId,
    ) {}

    public function handle(SmsSender $sms): void
    {
        $sms->send($this->phone, $this->body);
    }

    public function failed(?Throwable $exception): void
    {
        // Customer id, not the number — see NotificationService::swallow.
        Log::warning('Customer notification failed after retries.', [
            'template' => $this->templateKey,
            'customer_id' => $this->customerId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
