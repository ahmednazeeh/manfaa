<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Transfers\BankHistoryClient;
use App\Models\TransferProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hide a spent credit from the shared bank feed (owner, 2026-08-21).
 *
 * BML history is read by Manfaa AND IsleBooks. Manfaa's own dedup stops at
 * this database, so the gateway's mark-used call is what stops the other
 * platform claiming a credit we already settled.
 *
 * Queued and retried, deliberately:
 *
 *  - It runs AFTER the match commits. Hiding a credit for a match that then
 *    rolled back would lose the money to both platforms.
 *  - It must NOT be able to fail the match. The money is reconciled either
 *    way; what a failure costs is the cross-platform guard, and that is worth
 *    retrying rather than unwinding a settled bill for.
 *  - Backoff, because the window this closes is small but real: until the
 *    call lands, the credit is still visible to IsleBooks.
 */
final class MarkBankCreditUsed implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(
        private readonly int $profileId,
        private readonly string $reference,
    ) {}

    public function handle(BankHistoryClient $bank): void
    {
        $profile = TransferProfile::query()->find($this->profileId);

        if ($profile === null) {
            return;
        }

        // Throws on a refused request, so the queue retries. A `false` return
        // means the gateway answered but matched no row — retrying will not
        // change that, and markUsed() has already logged it.
        $bank->markUsed($profile, $this->reference);
    }
}
