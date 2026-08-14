<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\Postings;
use App\Models\LedgerJournal;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->postings = new Postings(new LedgerPoster);
    $this->balances = new Balances;
});

/**
 * Journals whose per-currency debit and credit totals differ — must always be empty.
 *
 * @return list<object>
 */
function unbalancedJournalRows(): array
{
    return DB::select(<<<'SQL'
        SELECT journal_id, currency, SUM(debit_laari) - SUM(credit_laari) AS net
        FROM ledger_entries
        GROUP BY journal_id, currency
        HAVING SUM(debit_laari) <> SUM(credit_laari)
        SQL);
}

it('posts a balanced journal for every §8 posting method', function () {
    $journalIds = [
        $this->postings->accrue(2000, 750, 60),
        $this->postings->bankSettlementReceived(2810),
        $this->postings->walletTopUp(500000),
        $this->postings->walletSettle(2810),
        $this->postings->payoutSent(2000),
        $this->postings->reverseAccrual(2000, 750, 60),
        $this->postings->writeOff(2000, 750, 60),
        $this->postings->platformFundedReward(2500),
    ];

    expect(array_unique($journalIds))->toHaveCount(8)
        ->and(LedgerJournal::query()->count())->toBe(8)
        ->and(unbalancedJournalRows())->toBe([])
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('omits the fee GST line when the GST amount is zero', function () {
    $withoutGst = $this->postings->accrue(2000, 750, 0);
    $withGst = $this->postings->accrue(2000, 750, 60);

    expect(DB::table('ledger_entries')->where('journal_id', $withoutGst)->count())->toBe(3)
        ->and(DB::table('ledger_entries')->where('journal_id', $withGst)->count())->toBe(4);
});

it('omits every zero-amount line so zero-fee rows can accrue, reverse and write off', function () {
    // Eligible 150 at 50bp/25bp rounds to cashback 1, fee 0 (§4) — a legal
    // row that must still post, reverse and write off without a zero line.
    $accrualId = $this->postings->accrue(1, 0, 0);
    $reversalId = $this->postings->reverseAccrual(1, 0, 0);
    $writeOffId = $this->postings->writeOff(2000, 0, 0);

    expect(DB::table('ledger_entries')->where('journal_id', $accrualId)->count())->toBe(2)
        ->and(DB::table('ledger_entries')->where('journal_id', $reversalId)->count())->toBe(2)
        ->and(DB::table('ledger_entries')->where('journal_id', $writeOffId)->count())->toBe(2)
        ->and(DB::table('ledger_entries')->where('debit_laari', 0)->where('credit_laari', 0)->count())->toBe(0)
        ->and(unbalancedJournalRows())->toBe([])
        ->and($this->balances->journalsAllBalance())->toBeTrue();
});

it('nets the receivable to zero after accrual and bank settlement', function () {
    $this->postings->accrue(2000, 750, 0);
    $this->postings->bankSettlementReceived(2750);

    expect($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->naturalBalance(AccountCode::CustomerCashbackLiability))->toBe(2000)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(750)
        ->and($this->balances->accountBalance(AccountCode::SettlementCash))->toBe(2750);

    // Documented raw convention: credit-normal accounts read negative as debit - credit.
    expect($this->balances->accountBalance(AccountCode::CustomerCashbackLiability))->toBe(-2000)
        ->and($this->balances->accountBalance(AccountCode::PlatformFeeRevenue))->toBe(-750);
});

it('reverses an accrual as an exact mirror, netting every account to zero', function () {
    $accrualId = $this->postings->accrue(2000, 750, 60);
    $reversalId = $this->postings->reverseAccrual(2000, 750, 60);

    foreach (AccountCode::cases() as $code) {
        expect($this->balances->accountBalance($code))->toBe(0, "account {$code->value} should net to zero");
    }

    // Line-level mirror: the reversal's debit per account equals the accrual's
    // credit, and vice versa.
    $accrual = DB::table('ledger_entries')->where('journal_id', $accrualId)->get()->keyBy('account_id');
    $reversal = DB::table('ledger_entries')->where('journal_id', $reversalId)->get()->keyBy('account_id');

    expect($reversal)->toHaveCount(count($accrual));

    foreach ($accrual as $accountId => $entry) {
        expect((int) $reversal[$accountId]->debit_laari)->toBe((int) $entry->credit_laari)
            ->and((int) $reversal[$accountId]->credit_laari)->toBe((int) $entry->debit_laari);
    }
});

it('writes off an unsettled accrual: receivable and liability net to zero, bad debt equals the fee', function () {
    $this->postings->accrue(2000, 750, 0);
    $this->postings->writeOff(2000, 750, 0);

    expect($this->balances->accountBalance(AccountCode::MerchantReceivable))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::CustomerCashbackLiability))->toBe(0)
        ->and($this->balances->accountBalance(AccountCode::BadDebtExpense))->toBe(750)
        ->and($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))->toBe(750)
        ->and(unbalancedJournalRows())->toBe([]);
});

it('produces a trial balance covering all eight accounts whose raw balances sum to zero', function () {
    $this->postings->accrue(2000, 750, 60);
    $this->postings->bankSettlementReceived(2810);
    $this->postings->platformFundedReward(2500);

    $trial = $this->balances->trialBalance();

    expect($trial)->toHaveCount(8)
        ->and($trial)->toHaveKey(AccountCode::MerchantReceivable->value)
        ->and(array_sum(array_column($trial, 'balance_laari')))->toBe(0)
        ->and($trial[AccountCode::FeeTaxPayable->value]['type'])->toBe('liability')
        ->and($trial[AccountCode::FeeTaxPayable->value]['credit_laari'])->toBe(60);
});
