<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Jobs\PollSettlementPayment;
use App\Jobs\PollWalletTopUp;
use App\Models\SettlementPayment;
use App\Models\WalletTopUp;
use Carbon\CarbonImmutable;

/**
 * Put the pollers back on the transfers that still have a window open, after
 * auto-verification is switched back ON (owner, 2026-08-25).
 *
 * WHY THIS EXISTS. A poll chain is a job that re-dispatches itself every
 * minute, and {@see PollSettlementPayment::handle} returns without
 * re-dispatching while the platform switch is down — so switching auto-verify
 * off does not pause the chains, it ENDS them. Switching it back on inside a
 * merchant's fifteen-minute window would otherwise leave rows whose
 * `poll_until` is still in the future with nothing behind them, and
 * {@see BankWatch} — reading the switch as it stands now — would tell the
 * merchant's screen `watching: true` over a bank nobody is reading.
 *
 * Two ways to keep that honest: stop claiming the watch, or restart it. This
 * restarts it, because the window really is still open and the merchant is
 * still owed the automatic check they would have got a minute earlier.
 *
 * ONLY ARMED ROWS. A row with no `poll_until` was uploaded while the switch
 * was down and was never armed; it belongs to the admin queue and BankWatch
 * says `never_watched` about it. This does not adopt those — arming them
 * late would start a fifteen-minute clock the merchant never saw the start
 * of, and the team is already handling them.
 */
final class BankWatchResumer
{
    /**
     * @return array{int, int} settlement payments and top-ups re-dispatched
     */
    public function resume(): array
    {
        $now = CarbonImmutable::now();

        $payments = SettlementPayment::query()
            ->where('state', 'pending')
            ->whereNotNull('poll_until')
            ->where('poll_until', '>', $now)
            ->pluck('id');

        foreach ($payments as $id) {
            PollSettlementPayment::dispatch((int) $id);
        }

        $topUps = WalletTopUp::query()
            ->where('state', 'pending')
            ->whereNotNull('poll_until')
            ->where('poll_until', '>', $now)
            ->pluck('id');

        foreach ($topUps as $id) {
            PollWalletTopUp::dispatch((int) $id);
        }

        return [$payments->count(), $topUps->count()];
    }
}
