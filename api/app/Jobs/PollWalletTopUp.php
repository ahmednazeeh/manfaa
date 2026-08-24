<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\WalletTopUpVerifier;
use App\Models\TransferSetting;
use App\Models\WalletTopUp;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Watch the bank for one merchant's wallet top-up transfer, bounded exactly
 * as {@see PollSettlementPayment} is: the window lives on the row so a
 * restarted worker resumes where the clock is, and the job re-queues rather
 * than sleeping so it does not hold a worker for a quarter of an hour.
 */
class PollWalletTopUp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const int INTERVAL_SECONDS = 60;

    /**
     * Slack above the window's own minute count, so an honest poll is never
     * cut short by a slow worker.
     */
    private const int EXTRA_ATTEMPTS = 5;

    public function __construct(private readonly int $topUpId) {}

    public function handle(WalletTopUpVerifier $verifier): void
    {
        $settings = TransferSetting::current();

        if (! $settings->auto_verify_enabled) {
            return;
        }

        $topUp = WalletTopUp::query()->with('merchant')->find($this->topUpId);

        if ($topUp === null || $topUp->state !== 'pending') {
            return;
        }

        if ($topUp->poll_until !== null && CarbonImmutable::now()->greaterThan($topUp->poll_until)) {
            // The window closed. The claim stays in the admin queue, which
            // is where an unmatched transfer belongs.
            return;
        }

        $attempts = (int) $topUp->poll_attempts + 1;
        $topUp->forceFill(['poll_attempts' => $attempts])->save();

        if ($verifier->attempt($topUp)) {
            return;
        }

        // Bounded by attempts as well as by the clock, because a synchronous
        // queue ignores `delay()` and a self-re-dispatching job then
        // recurses with no time passing.
        if ($attempts >= (int) $settings->verify_window_minutes + self::EXTRA_ATTEMPTS) {
            return;
        }

        self::dispatch($this->topUpId)->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }
}
