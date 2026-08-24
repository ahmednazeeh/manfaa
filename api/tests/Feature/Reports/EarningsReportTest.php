<?php

declare(strict_types=1);

use App\Domain\Adjustment\ReversalService;
use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Ledger\AccountCode;
use App\Domain\Ledger\Balances;
use App\Domain\Ledger\Postings;
use App\Domain\Money\Laari;
use App\Domain\Reports\EarningsReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Settlement\SettlementState;
use App\Domain\Settlement\WalletFunding;
use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->balances = new Balances;
});

afterEach(function () {
    Carbon::setTestNow();
});

function augustEarnings(?int $merchantId = null): EarningsReport
{
    return new EarningsReport(ReportPeriod::of('2026-08-01', '2026-08-31'), $merchantId);
}

/**
 * A whole month of money, built through the real posting catalogue: fees
 * accrued, a batch discounted and settled, a batch forgiven a shortfall, a
 * write-off, a wallet settlement and a referral reward.
 *
 * @return array<string, mixed>
 */
function augustLedger(): array
{
    // Discounted and settled to the laari: 3,225 of fee, 162 off at 500bp.
    $discounted = ReportFixture::payable([100_000, 50_000, 200_000, 80_000], discountRateBp: 500, merchantName: 'Discount Shop');
    $discounted->payAndMatch($discounted->submit(), 11_663);

    // Forty-five laari short — §7 forgives it and the batch settles.
    $forgiven = ReportFixture::payable([100_000, 50_000, 200_000, 80_000], discountRateBp: 0, merchantName: 'Short Shop');
    $forgiven->payAndMatch($forgiven->submit(), 11_780);

    // A batch paid out of the merchant's wallet rather than the bank.
    $wallet = ReportFixture::payable([100_000], discountRateBp: 0, merchantName: 'Wallet Shop');
    $walletSettlement = $wallet->submit();
    app(WalletFunding::class)->recordTopUp($wallet->merchant, Laari::of(2_750), 'BML-TOPUP-1');
    app(WalletFunding::class)->settleFromWallet($walletSettlement, $wallet->user);

    // A merchant who never paid: the platform's own margin becomes bad debt.
    $defaulted = ReportFixture::payable([100_000], discountRateBp: 0, merchantName: 'Gone Shop');
    $written = $defaulted->transactions[0];
    app(TransitionService::class)->writeOff($written, Actor::system(), 'ninety_days_overdue');
    app(Postings::class)->writeOff(
        $written->cashback_laari,
        $written->fee_laari,
        $written->fee_gst_laari,
        referenceId: $written->id,
    );

    // And a reward the platform funded itself.
    app(Postings::class)->platformFundedReward(5_000, referenceId: $discounted->transactions[0]->id);

    return compact('discounted', 'forgiven', 'wallet', 'defaulted', 'walletSettlement', 'written');
}

it('reads the whole money trace out of the ledger, and never out of the transactions table', function () {
    $month = augustLedger();

    expect($month['walletSettlement']->refresh()->state)->toBe(SettlementState::Settled);

    $summary = augustEarnings()->summary();

    // Fees accrued: 3,225 + 3,225 + 750 + 750.
    expect($summary['fee_revenue_laari'])->toBe(7_950)
        ->and($summary['prompt_discounts_laari'])->toBe(162)
        ->and($summary['shortfall_forgiveness_laari'])->toBe(45)
        ->and($summary['net_fee_income_laari'])->toBe(7_950 - 162 - 45)
        // GST is switched off everywhere today; it is a LIABILITY line, and
        // it must never join the earnings arithmetic when it is not.
        ->and($summary['gst_collected_laari'])->toBe(0)
        // The referral only — the forgiven shortfall hits the same account
        // and is counted on its own line.
        ->and($summary['platform_funded_rewards_laari'])->toBe(5_000)
        ->and($summary['bad_debt_laari'])->toBe(750)
        ->and($summary['net_platform_earnings_laari'])->toBe(7_950 - 162 - 45 - 5_000 - 750);

    // Every figure above is the ledger's own, not a derivation of it.
    expect($summary['fee_revenue_laari'] - $summary['prompt_discounts_laari'])
        ->toBe($this->balances->naturalBalance(AccountCode::PlatformFeeRevenue))
        ->and($summary['bad_debt_laari'])->toBe($this->balances->accountBalance(AccountCode::BadDebtExpense))
        ->and($summary['platform_funded_rewards_laari'] + $summary['shortfall_forgiveness_laari'])
        ->toBe($this->balances->naturalBalance(AccountCode::PlatformFundedRewards));
});

it('splits accrued from collected, and bank collection from wallet collection', function () {
    augustLedger();

    $summary = augustEarnings()->summary();

    // Everything accrued this month; only the settled batches collected —
    // and "collected" is CASH, so the 162 of relief the discounted batch was
    // given never arrived and is not counted. Left in, a month where relief
    // was granted would read accrued == collected while 4100 in the same
    // workbook showed the fee being discounted away.
    expect($summary['accrued_vs_collected']['fees_accrued_laari'])->toBe(7_950)
        ->and($summary['accrued_vs_collected']['fees_collected_bank_laari'])->toBe(3_225 + 3_225 - 162)
        ->and($summary['accrued_vs_collected']['fees_collected_wallet_laari'])->toBe(750)
        ->and($summary['accrued_vs_collected']['fees_collected_laari'])->toBe(7_200 - 162);

    // The 750 of fee on the written-off sale accrued and was never
    // collected, and the 162 was discounted away — which is the whole point
    // of showing both.
    expect($summary['accrued_vs_collected']['fees_accrued_laari'] - $summary['accrued_vs_collected']['fees_collected_laari'])
        ->toBe(750 + 162);

    // Collected can never exceed what accrued less the relief given on it.
    expect($summary['accrued_vs_collected']['fees_collected_laari'])
        ->toBeLessThanOrEqual($summary['accrued_vs_collected']['fees_accrued_laari'] - $summary['prompt_discounts_laari']);
});

it('agrees with the trial balance account for account', function () {
    augustLedger();

    $sheet = augustEarnings()->sheet(EarningsReport::BY_ACCOUNT);
    $trial = $this->balances->trialBalance();

    $codeIndex = $sheet->indexOf('code');

    foreach ($sheet->rows() as $row) {
        $code = (string) $row[$codeIndex];

        expect((int) $row[$sheet->indexOf('debit_laari')])->toBe($trial[$code]['debit_laari'])
            ->and((int) $row[$sheet->indexOf('credit_laari')])->toBe($trial[$code]['credit_laari'])
            ->and((int) $row[$sheet->indexOf('net_laari')])->toBe($trial[$code]['balance_laari']);
    }

    // A period that covers every journal must balance to zero overall, and
    // the two sides must be equal — the §5 invariant, restated by the sheet.
    expect($sheet->sum('net_laari'))->toBe(0)
        ->and($sheet->sum('debit_laari'))->toBe($sheet->sum('credit_laari'));
});

it('breaks the same money down per merchant', function () {
    $month = augustLedger();

    $sheet = augustEarnings()->sheet(EarningsReport::BY_MERCHANT);
    $nameIndex = $sheet->indexOf('merchant');

    $row = collect($sheet->rows())->firstWhere($nameIndex, 'Discount Shop');

    // Collected is net of the relief, exactly as the summary's own
    // "Fees collected" is — the two are the same number arrived at the
    // same way, and the workbook must not ship both.
    expect($row[$sheet->indexOf('fees_accrued_laari')])->toBe(3_225)
        ->and($row[$sheet->indexOf('discounts_laari')])->toBe(162)
        ->and($row[$sheet->indexOf('gst_laari')])->toBe(0)
        ->and($row[$sheet->indexOf('collected_laari')])->toBe(3_225 - 162);

    $gone = collect($sheet->rows())->firstWhere($nameIndex, 'Gone Shop');

    // Accrued, never collected: the shop that walked away.
    expect($gone[$sheet->indexOf('fees_accrued_laari')])->toBe(750)
        ->and($gone[$sheet->indexOf('collected_laari')])->toBe(0);

    expect($sheet->sum('fees_accrued_laari'))->toBe(7_950)
        ->and($sheet->sum('collected_laari'))->toBe(7_200 - 162)
        ->and($sheet->count())->toBe(4)
        ->and($month['forgiven']->merchant->name)->toBe('Short Shop');

    // The workbook must never ship two figures for one fact: this sheet's
    // totals row and the Summary sheet beside it are the same money, so
    // they are asserted against each other rather than against two
    // separately-maintained constants.
    $summary = augustEarnings()->summary();

    expect($sheet->sum('fees_accrued_laari') - $sheet->sum('discounts_laari'))
        ->toBe($summary['fee_revenue_laari'] - $summary['prompt_discounts_laari'])
        ->and($sheet->sum('collected_laari'))
        ->toBe($summary['accrued_vs_collected']['fees_collected_laari']);
});

it('lists every journal line in the period, and the postings sheet balances', function () {
    augustLedger();

    $report = augustEarnings();
    $postings = $report->sheet(EarningsReport::POSTINGS);

    expect($postings->count())->toBe($report->rowCount())
        ->and($postings->sum('debit_laari'))->toBe($postings->sum('credit_laari'));

    // Words, not codes, in the reference-type column; the memo is the §8
    // catalogue's own description.
    $memos = collect($postings->rows())->map(fn (array $row): string => (string) $row[$postings->indexOf('memo')])->unique()->all();

    expect($memos)->toContain('Cashback accrued')
        ->and($memos)->toContain('Prompt-payment fee discount')
        ->and($memos)->toContain('Settlement shortfall forgiven')
        ->and($memos)->toContain('Unsettled reward written off')
        ->and($memos)->toContain('Platform-funded reward granted')
        ->and($memos)->toContain('Wallet settlement applied');

    $types = collect($postings->rows())->map(fn (array $row): string => (string) $row[$postings->indexOf('reference_type')])->unique()->all();

    expect($types)->toContain('Transaction')
        ->and($types)->toContain('Settlement');

    expect(array_map(fn ($each) => $each->title, $report->sheets()))->toBe([
        EarningsReport::SUMMARY,
        EarningsReport::BY_ACCOUNT,
        EarningsReport::BY_MERCHANT,
        EarningsReport::POSTINGS,
    ]);
});

it('narrows to one merchant, journal by journal', function () {
    $month = augustLedger();

    $summary = augustEarnings($month['discounted']->merchant->id)->summary();

    expect($summary['fee_revenue_laari'])->toBe(3_225)
        ->and($summary['prompt_discounts_laari'])->toBe(162)
        ->and($summary['shortfall_forgiveness_laari'])->toBe(0)
        ->and($summary['bad_debt_laari'])->toBe(0)
        ->and($summary['accrued_vs_collected']['fees_collected_bank_laari'])->toBe(3_225 - 162)
        ->and($summary['accrued_vs_collected']['fees_collected_wallet_laari'])->toBe(0);
});

it('finds nothing in a period nothing was posted in', function () {
    augustLedger();

    $july = new EarningsReport(ReportPeriod::of('2026-07-01', '2026-07-31'), null);

    expect($july->rowCount())->toBe(0)
        ->and($july->summary()['net_platform_earnings_laari'])->toBe(0)
        ->and($july->sheet(EarningsReport::POSTINGS)->count())->toBe(0)
        ->and($july->sheet(EarningsReport::BY_MERCHANT)->count())->toBe(0);
});

/*
 * §7 REGRESSION: refunding an already-confirmed sale is the ordinary way a
 * credit memo appears, and Postings::applyAdjustmentCredit posts its 4100
 * and 2300 reversals under `reference_type = 'adjustment'`. The per-merchant
 * accrual query joined `transaction` journals alone, so the Summary sheet
 * lost the fee and no merchant on the sheet beside it gave it up — one
 * workbook, two figures, one fact, with a =SUM() totals row underneath.
 */
it('gives the adjustment reversal back to the merchant it came from', function () {
    $fixture = ReportFixture::payable([100_000, 50_000], discountRateBp: 0, merchantName: 'Adjust Shop');

    $settled = $fixture->submit();
    $fixture->payAndMatch($settled, $settled->amount_due_laari);

    // An admin reverses a confirmed sale: a §7 credit memo, not a reversal
    // in place.
    $outcome = app(ReversalService::class)->reverse(
        $fixture->transactions[0]->refresh(),
        Actor::admin(AdminUser::factory()->create()->id),
        'customer_refund',
        CarbonImmutable::now('UTC'),
        'returned goods',
    );

    expect($outcome->outcome)->toBe('adjustment_created');

    // A fresh sale, then the batch that nets the credit in — which is where
    // applyAdjustmentCredit posts.
    $later = app(ManualCreditService::class)->credit(
        $fixture->merchant,
        $fixture->user,
        $fixture->customer->customer_code,
        'INV-AFTER-CREDIT',
        Laari::of(200_000),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    );

    Carbon::setTestNow(CarbonImmutable::now('UTC')->addDays(4));
    app(TransitionService::class)->makePayable($later, Actor::system());

    $netted = $fixture->submit();
    $fixture->payAndMatch($netted, $netted->amount_due_laari);

    $report = augustEarnings();
    $sheet = $report->sheet(EarningsReport::BY_MERCHANT);
    $summary = $report->summary();

    // The reversal debited 750 of fee. Both halves of the workbook must have
    // given it up.
    expect($sheet->count())->toBe(1)
        ->and($sheet->sum('fees_accrued_laari'))->toBe($summary['fee_revenue_laari'])
        ->and($sheet->sum('gst_laari'))->toBe($summary['gst_collected_laari'])
        ->and($sheet->sum('fees_accrued_laari'))->toBe(2_625 - 750);
});
