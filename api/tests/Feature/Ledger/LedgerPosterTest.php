<?php

declare(strict_types=1);

use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\InvalidJournalLineException;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\UnbalancedJournalException;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->poster = new LedgerPoster;
});

it('posts a balanced journal and returns its id with posted_at set', function () {
    $journalId = $this->poster->post('transaction', 42, 'Cashback accrued', [
        ['account' => AccountCode::MerchantReceivable, 'debit_laari' => 2750],
        ['account' => AccountCode::CustomerCashbackLiability, 'credit_laari' => 2000],
        ['account' => AccountCode::PlatformFeeRevenue, 'credit_laari' => 750],
    ]);

    $journal = LedgerJournal::query()->findOrFail($journalId);

    expect($journal->reference_type)->toBe('transaction')
        ->and($journal->reference_id)->toBe(42)
        ->and($journal->posted_at)->not->toBeNull()
        ->and($journal->entries()->count())->toBe(3)
        ->and((int) $journal->entries()->sum('debit_laari'))->toBe(2750)
        ->and((int) $journal->entries()->sum('credit_laari'))->toBe(2750);
});

it('throws UnbalancedJournalException and writes no partial rows for an unbalanced post', function () {
    expect(fn () => $this->poster->post('test', 1, 'Unbalanced', [
        ['account' => AccountCode::MerchantReceivable, 'debit_laari' => 2750],
        ['account' => AccountCode::CustomerCashbackLiability, 'credit_laari' => 2000],
    ]))->toThrow(UnbalancedJournalException::class);

    expect(DB::table('ledger_journals')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

it('rejects a line carrying both a debit and a credit', function () {
    expect(fn () => $this->poster->post('test', 1, 'Both sides', [
        ['account' => AccountCode::SettlementCash, 'debit_laari' => 100, 'credit_laari' => 100],
        ['account' => AccountCode::MerchantReceivable, 'credit_laari' => 100],
    ]))->toThrow(InvalidJournalLineException::class);

    expect(DB::table('ledger_journals')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

it('rejects a line with neither side positive', function () {
    expect(fn () => $this->poster->post('test', 1, 'Zero line', [
        ['account' => AccountCode::SettlementCash, 'debit_laari' => 100],
        ['account' => AccountCode::MerchantReceivable],
        ['account' => AccountCode::MerchantWalletBalance, 'credit_laari' => 100],
    ]))->toThrow(InvalidJournalLineException::class);
});

it('rejects a negative amount on either side', function () {
    expect(fn () => $this->poster->post('test', 1, 'Negative', [
        ['account' => AccountCode::SettlementCash, 'debit_laari' => -100],
        ['account' => AccountCode::MerchantReceivable, 'credit_laari' => -100],
    ]))->toThrow(InvalidJournalLineException::class);
});

it('balances per currency, not across currencies', function () {
    expect(fn () => $this->poster->post('test', 1, 'Cross-currency', [
        ['account' => AccountCode::SettlementCash, 'debit_laari' => 100, 'currency' => 'MVR'],
        ['account' => AccountCode::MerchantReceivable, 'credit_laari' => 100, 'currency' => 'USD'],
    ]))->toThrow(UnbalancedJournalException::class);
});

it('seeds the eight chart accounts idempotently', function () {
    $this->seed(LedgerAccountSeeder::class);

    expect(LedgerAccount::query()->count())->toBe(8)
        ->and(LedgerAccount::query()->where('code', AccountCode::MerchantReceivable->value)->value('type'))->toBe('asset')
        ->and(LedgerAccount::query()->where('code', AccountCode::CustomerCashbackLiability->value)->value('type'))->toBe('liability')
        ->and(LedgerAccount::query()->where('code', AccountCode::PlatformFeeRevenue->value)->value('type'))->toBe('income')
        ->and(LedgerAccount::query()->where('code', AccountCode::BadDebtExpense->value)->value('type'))->toBe('expense')
        ->and(LedgerAccount::query()->where('scope', '!=', 'global')->count())->toBe(0);
});
