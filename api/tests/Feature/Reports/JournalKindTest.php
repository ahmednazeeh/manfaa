<?php

declare(strict_types=1);

use App\Domain\Ledger\Postings;
use App\Domain\Reports\JournalKind;
use App\Models\LedgerJournal;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The earnings report tells an accrual from a discount by reading the
 * journal's DESCRIPTION — `ledger_journals` has no kind column. That makes a
 * dozen English sentences load-bearing, and a renamed description would
 * silently turn a report figure to zero rather than break anything.
 *
 * So every case of JournalKind is posted here through the real §8 catalogue
 * and compared with what actually landed in the table. Rename one and this
 * fails, loudly, next to the report that depends on it.
 */
it('matches every description the §8 posting catalogue writes', function () {
    $this->seed(LedgerAccountSeeder::class);

    $postings = app(Postings::class);

    $posted = [
        JournalKind::Accrual->value => $postings->accrue(2_000, 750, 100),
        JournalKind::AccrualReversed->value => $postings->reverseAccrual(2_000, 750, 100),
        JournalKind::BankSettlementReceived->value => $postings->bankSettlementReceived(2_850),
        JournalKind::WalletTopUp->value => $postings->walletTopUp(1_000),
        JournalKind::WalletSettle->value => $postings->walletSettle(1_000),
        JournalKind::PayoutSent->value => $postings->payoutSent(2_000),
        JournalKind::AdjustmentCreditApplied->value => $postings->applyAdjustmentCredit(100, 10, 1),
        JournalKind::AdjustmentCreditUnapplied->value => $postings->unapplyAdjustmentCredit(100, 10, 1),
        JournalKind::WriteOff->value => $postings->writeOff(2_000, 750, 100),
        JournalKind::ShortfallForgiven->value => $postings->forgiveSettlementShortfall(45),
        JournalKind::PromptDiscount->value => $postings->promptPaymentDiscount(38, 4),
        JournalKind::PlatformFundedReward->value => $postings->platformFundedReward(5_000),
    ];

    foreach ($posted as $description => $journalId) {
        expect(LedgerJournal::query()->findOrFail($journalId)->description)->toBe($description);
    }

    // And no case of the enum went untested.
    expect(array_keys($posted))->toEqualCanonicalizing(
        array_map(static fn (JournalKind $kind): string => $kind->value, JournalKind::cases()),
    );
});
