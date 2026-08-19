<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\SettlementPaymentVerifier;
use App\Models\SettlementPayment;
use App\Models\TransferSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Watch the bank for one merchant's settlement transfer, bounded exactly as
 * the customer-side poll is.
 *
 * Same shape, same reasons: the window lives on the row so a restarted
 * worker resumes where the clock is, and the job re-queues rather than
 * sleeping so it does not hold a worker for a quarter of an hour.
 */
class PollSettlementPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const int INTERVAL_SECONDS = 60;

    public function __construct(private readonly int $paymentId) {}

    public function handle(SettlementPaymentVerifier $verifier): void
    {
        if (! TransferSetting::current()->auto_verify_enabled) {
            return;
        }

        $payment = SettlementPayment::query()
            ->with('settlement', 'merchant')
            ->find($this->paymentId);

        if ($payment === null || $payment->state !== 'pending') {
            return;
        }

        if ($payment->poll_until !== null && CarbonImmutable::now()->greaterThan($payment->poll_until)) {
            // The window closed. The payment stays in the settlement queue,
            // which is where an unmatched transfer belongs.
            return;
        }

        $payment->forceFill(['poll_attempts' => (int) $payment->poll_attempts + 1])->save();

        if ($verifier->attempt($payment)) {
            return;
        }

        self::dispatch($this->paymentId)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }
}
