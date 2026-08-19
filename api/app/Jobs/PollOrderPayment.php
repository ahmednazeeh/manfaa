<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\PaymentVerifier;
use App\Models\Order;
use App\Models\TransferSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Watch the bank for one order's money (owner spec: 15 minutes).
 *
 * The window is BOUNDED and re-checked from the row rather than counted in
 * the job, so a restarted worker resumes where the clock actually is instead
 * of starting the fifteen minutes again. When it expires the order simply
 * stays in the admin queue, which is where an unmatched payment belongs.
 *
 * Re-queues itself rather than sleeping: a job holding a worker for a
 * quarter of an hour is a worker not doing anything else.
 */
class PollOrderPayment implements ShouldQueue
{
    use Queueable;

    /** One attempt per firing — the loop is the re-queue, not a retry. */
    public int $tries = 1;

    private const int INTERVAL_SECONDS = 60;

    public function __construct(private readonly int $orderId) {}

    public function handle(PaymentVerifier $verifier): void
    {
        $settings = TransferSetting::current();

        if (! $settings->auto_verify_enabled) {
            // Switched off while this was queued. Stop quietly — the admin
            // queue still holds the order.
            return;
        }

        $order = Order::query()->with('customer')->find($this->orderId);

        if ($order === null || $order->payment_state !== 'proof_submitted') {
            return;
        }

        $now = CarbonImmutable::now();

        if ($order->poll_until !== null && $now->greaterThan($order->poll_until)) {
            return;
        }

        $order->forceFill(['poll_attempts' => $order->poll_attempts + 1])->save();

        if ($verifier->attempt($order)) {
            return;
        }

        // Not yet. Look again in a minute, until the window closes.
        self::dispatch($this->orderId)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }
}
