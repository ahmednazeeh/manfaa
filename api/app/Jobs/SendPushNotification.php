<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Push\PushDeliveryException;
use App\Domain\Push\PushSender;
use App\Models\DeviceToken;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One push, to one device, off the money path.
 *
 * Same discipline as SendCustomerSms and for the same reasons: the provider
 * is a network call, and a merchant pressing Credit must not wait on
 * Google's availability. Retries a few times and then gives up quietly — a
 * notification is not a ledger entry, and the truth is always in the app.
 *
 * One job per DEVICE rather than one per person. A failure is per-token
 * (this phone uninstalled the app; that one did not), and batching them
 * would either retry the healthy devices needlessly or let one dead token
 * suppress the rest.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** 1m, then 5m — a provider blip recovers inside that; an outage does not. */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly int $deviceTokenId,
        private readonly string $title,
        private readonly string $body,
        private readonly string $templateKey,
    ) {}

    public function handle(PushSender $push): void
    {
        $device = DeviceToken::query()->find($this->deviceTokenId);

        // Registered, then revoked before the queue got here — signing out
        // during a payout run does exactly this. Nothing to deliver and
        // nothing wrong.
        if ($device === null) {
            return;
        }

        try {
            $push->send($device->token, $this->title, $this->body, [
                'template' => $this->templateKey,
            ]);
        } catch (PushDeliveryException $exception) {
            if (! $exception->permanent) {
                throw $exception;
            }

            // The provider says this registration is dead — the app was
            // uninstalled, or the token rotated. Retrying it forever would
            // be noise; delete it so the device list stops offering a device
            // that cannot be reached.
            $device->delete();
        }
    }

    public function failed(?Throwable $exception): void
    {
        // The template and the row id — never the device token, which is a
        // credential and does not belong in a log.
        Log::warning('Push notification gave up.', [
            'template' => $this->templateKey,
            'device_token_id' => $this->deviceTokenId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
