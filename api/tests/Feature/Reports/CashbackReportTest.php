<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Money\Laari;
use App\Domain\Platform\PlatformConfig;
use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\EarningsReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Settlement\SettlementAllocator;
use App\Domain\Settlement\SettlementBuilder;
use App\Domain\Settlement\SettlementState;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantBranch;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function augustReport(?int $merchantId = null): CashbackReport
{
    return new CashbackReport(ReportPeriod::of('2026-08-01', '2026-08-31'), $merchantId);
}

/** The value under one column of the row whose invoice number is given. */
function cashbackCell(CashbackReport $report, string $invoiceNo, string $column): mixed
{
    $sheet = $report->sheet(CashbackReport::TRANSACTIONS);
    $invoiceIndex = $sheet->indexOf('invoice_no');
    $columnIndex = $sheet->indexOf($column);

    foreach ($sheet->rows() as $row) {
        if ($row[$invoiceIndex] === $invoiceNo) {
            return $row[$columnIndex];
        }
    }

    throw new RuntimeException(sprintf('No row for invoice %s.', $invoiceNo));
}

it('includes and excludes by occurred_at at the BUSINESS-timezone boundary', function () {
    $merchant = Merchant::factory()->create();
    $customer = Customer::factory()->create();

    // The Maldives is five hours ahead of UTC, so the first two hours of a
    // Maldivian day happened YESTERDAY in UTC. A report periodised in UTC
    // silently drops them; this one must not.
    $moments = [
        'IN-AUG-FIRST-MINUTE' => '2026-08-01T00:00:00+05:00',
        'IN-AUG-UTC-TRAP' => '2026-08-01T02:00:00+05:00',
        'IN-AUG-LAST-MINUTE' => '2026-08-31T23:59:00+05:00',
        'OUT-JULY-LAST-MINUTE' => '2026-07-31T23:59:00+05:00',
        'OUT-SEPT-FIRST-MINUTE' => '2026-09-01T00:01:00+05:00',
    ];

    foreach ($moments as $invoice => $moment) {
        // ->utc() deliberately: Eloquent formats a datetime binding without
        // its offset, so a +05:00 instant handed over as-is is STORED five
        // hours late (the trap PayoutBatchBuilder documents). The fixture
        // has to write the instant the platform would have written.
        $at = CarbonImmutable::parse($moment)->utc();

        Transaction::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'invoice_no' => $invoice,
            'occurred_at' => $at,
            'received_at' => $at,
        ]);
    }

    $invoices = collect(augustReport()->sheet(CashbackReport::TRANSACTIONS)->rows())
        ->map(fn (array $row): string => (string) $row[3])
        ->all();

    expect($invoices)->toEqualCanonicalizing([
        'IN-AUG-FIRST-MINUTE',
        'IN-AUG-UTC-TRAP',
        'IN-AUG-LAST-MINUTE',
    ]);
});

it('renders states, origins and the masked customer name as words, never as codes', function () {
    $merchant = Merchant::factory()->create(['name' => 'Sea House Cafe']);
    $branch = MerchantBranch::factory()->for($merchant)->create(['name' => 'Hulhumalé Branch']);
    $customer = Customer::factory()->create(['name' => 'Aishath Mohamed', 'customer_code' => '482917']);

    foreach ([
        ['PAYABLE-1', TransactionState::PayableUnfunded, 'api_phone'],
        ['HELD-1', TransactionState::OnHold, 'pos'],
        ['WRITTEN-1', TransactionState::WrittenOff, 'claim'],
    ] as [$invoice, $state, $origin]) {
        Transaction::factory()->create([
            'merchant_id' => $merchant->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_no' => $invoice,
            'origin' => $origin,
            'state' => $state->value,
            'occurred_at' => CarbonImmutable::parse('2026-08-12T10:00:00+05:00')->utc(),
            'received_at' => CarbonImmutable::parse('2026-08-12T10:00:00+05:00')->utc(),
        ]);
    }

    $report = augustReport();

    expect(cashbackCell($report, 'PAYABLE-1', 'state'))->toBe('payable (unfunded)')
        ->and(cashbackCell($report, 'HELD-1', 'state'))->toBe('on hold')
        ->and(cashbackCell($report, 'WRITTEN-1', 'state'))->toBe('written off')
        ->and(cashbackCell($report, 'PAYABLE-1', 'origin'))->toBe('API (phone)')
        ->and(cashbackCell($report, 'WRITTEN-1', 'origin'))->toBe('Claim')
        ->and(cashbackCell($report, 'PAYABLE-1', 'merchant'))->toBe('Sea House Cafe')
        ->and(cashbackCell($report, 'PAYABLE-1', 'branch'))->toBe('Hulhumalé Branch')
        ->and(cashbackCell($report, 'PAYABLE-1', 'customer_code'))->toBe('482917')
        // Masked, because whoever reads a platform-wide report is not the
        // customer whose name it is.
        ->and(cashbackCell($report, 'PAYABLE-1', 'customer'))->toBe('Ais*** Moh***');
});

it('leaves collected BLANK on a transaction no settlement has covered', function () {
    $merchant = Merchant::factory()->create();

    Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => Customer::factory(),
        'invoice_no' => 'UNSETTLED-1',
        'state' => TransactionState::Tracked->value,
        'occurred_at' => CarbonImmutable::parse('2026-08-10T10:00:00+05:00')->utc(),
        'received_at' => CarbonImmutable::parse('2026-08-10T10:00:00+05:00')->utc(),
    ]);

    $report = augustReport();

    expect(cashbackCell($report, 'UNSETTLED-1', 'collected_laari'))->toBeNull()
        ->and(cashbackCell($report, 'UNSETTLED-1', 'settlement_ref'))->toBe('')
        ->and(cashbackCell($report, 'UNSETTLED-1', 'state'))->toBe('tracked')
        // A blank collection is not a zero one: the totals row must not
        // claim the batch collected something it did not.
        ->and($report->sheet(CashbackReport::TRANSACTIONS)->sum('collected_laari'))->toBe(0);
});

it('sums the collected column to exactly what the merchant transferred', function () {
    $fixture = ReportFixture::payable([100_000, 50_000, 200_000, 80_000], discountRateBp: 500);
    $settlement = $fixture->payAndMatch($fixture->submit(), 11_663);

    $report = augustReport();
    $transactions = $report->sheet(CashbackReport::TRANSACTIONS);
    $settlements = $report->sheet(CashbackReport::SETTLEMENTS);

    expect($transactions->count())->toBe(4)
        ->and($transactions->sum('gross_due_laari'))->toBe(11_825)
        ->and($transactions->sum('discount_laari'))->toBe(162)
        ->and($transactions->sum('collected_laari'))->toBe($settlement->amount_received_laari)
        ->and($transactions->sum('collected_laari'))->toBe(11_663);

    // Every settled line names its batch, its funding and when it landed.
    $sheet = $transactions->rows()[0];

    expect($sheet[$transactions->indexOf('settlement_ref')])->toBe($settlement->reference)
        ->and($sheet[$transactions->indexOf('funding_method')])->toBe('Bank')
        ->and($sheet[$transactions->indexOf('settled_at')])->not->toBeNull()
        ->and($sheet[$transactions->indexOf('state')])->toBe('confirmed');

    // And the batch sheet states the same money one level up.
    expect($settlements->count())->toBe(1)
        ->and($settlements->sum('amount_received_laari'))->toBe(11_663)
        ->and($settlements->sum('discount_laari'))->toBe(162)
        ->and($settlements->rows()[0][$settlements->indexOf('discount_rate_bp')])->toBe(500)
        ->and($settlements->rows()[0][$settlements->indexOf('state')])->toBe('settled');
});

it('agrees with itself: the summary sheet, the totals and the JSON summary are one set of numbers', function () {
    $fixture = ReportFixture::payable([100_000, 50_000], discountRateBp: 500);
    $fixture->payAndMatch($fixture->submit(), 4_100);

    $report = augustReport();
    $summary = $report->summary();
    $transactions = $report->sheet(CashbackReport::TRANSACTIONS);

    expect($summary['transactions']['count'])->toBe($transactions->count())
        ->and($summary['transactions']['cashback_laari'])->toBe($transactions->sum('cashback_laari'))
        ->and($summary['transactions']['collected_laari'])->toBe($transactions->sum('collected_laari'))
        ->and($summary['settlements']['amount_received_laari'])->toBe(4_100);

    // The Summary sheet's grand-total row carries the same figures.
    $sheet = $report->sheet(CashbackReport::SUMMARY);
    $total = collect($sheet->rows())->firstWhere(0, 'Transactions — all states');

    expect((int) $total[1])->toBe($transactions->count())
        ->and((int) $total[3])->toBe($transactions->sum('cashback_laari'))
        ->and((int) $total[7])->toBe($transactions->sum('collected_laari'));

    // Summary first in the workbook, whatever order a reader thinks in.
    expect(array_map(fn ($each) => $each->title, $report->sheets()))
        ->toBe([CashbackReport::SUMMARY, CashbackReport::TRANSACTIONS, CashbackReport::SETTLEMENTS]);
});

it('narrows to one merchant when asked, and leaves the others out', function () {
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $mine = ReportFixture::payable([100_000], merchantName: 'Mine');
    $theirs = ReportFixture::payable([200_000], merchantName: 'Theirs');

    $all = augustReport();
    $filtered = augustReport($mine->merchant->id);

    expect($all->sheet(CashbackReport::TRANSACTIONS)->count())->toBe(2)
        ->and($filtered->sheet(CashbackReport::TRANSACTIONS)->count())->toBe(1)
        ->and($filtered->sheet(CashbackReport::TRANSACTIONS)->rows()[0][1])->toBe('Mine')
        ->and($filtered->rowCount())->toBe(1)
        ->and($theirs->merchant->name)->toBe('Theirs');
});

/*
 * §7 REGRESSION: the Settlements sheet is a money sheet — every row's
 * `amount_due_laari` lands in a `=SUM()` totals row and in the summary block
 * the admin tiles read. A batch that is a basket (draft) or a batch that was
 * replaced (cancelled) is not money anybody owes, and counting either one
 * turns a merchant's ordinary re-submission into double the amount due.
 */
it('keeps a cancelled batch and its re-submission from double-counting the money owed', function () {
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $fixture = ReportFixture::payable([100_000, 50_000]);

    // The merchant attaches the wrong slip; the admin rejects it, which
    // cancels the batch and releases the lines. They re-submit and pay.
    $first = $fixture->submit();
    app(SettlementAllocator::class)->recordBankPayment($first, Laari::of($first->amount_due_laari), 'BML-WRONG');
    app(SettlementBuilder::class)->reject($first->refresh(), AdminUser::factory()->create(), 'wrong slip');

    $second = $fixture->submit();
    $fixture->payAndMatch($second, $second->amount_due_laari);

    $report = augustReport();
    $sheet = $report->sheet(CashbackReport::SETTLEMENTS);
    $summary = $report->summary()['settlements'];

    // One live batch, and the merchant never owed more than one batch.
    expect($sheet->count())->toBe(1)
        ->and($sheet->rows()[0][$sheet->indexOf('state')])->toBe('settled')
        ->and($summary['amount_due_laari'])->toBe($fixture->dueTotal())
        ->and($summary['amount_received_laari'])->toBe($fixture->dueTotal())
        ->and($summary['by_state'])->toHaveCount(1)
        ->and($summary['by_state'][0]['state'])->toBe('settled');
});

it('leaves an abandoned draft off the settlements sheet entirely', function () {
    app(PlatformConfig::class)->set('prompt_discount_rate_bp', 0);

    $fixture = ReportFixture::payable([100_000, 50_000]);

    // Opened the settle page and walked away: rows exist, nothing is owed.
    app(SettlementBuilder::class)->createDraft($fixture->merchant);

    $report = augustReport();

    expect($report->sheet(CashbackReport::SETTLEMENTS)->count())->toBe(0)
        ->and($report->summary()['settlements']['amount_due_laari'])->toBe(0)
        // The sales themselves are still on the report, uncollected.
        ->and($report->sheet(CashbackReport::TRANSACTIONS)->count())->toBe(2)
        ->and($report->summary()['transactions']['collected_laari'])->toBe(0);
});

/*
 * §1 REGRESSION: the prompt-payment discount is WITHHELD from any allocation
 * that does not finish the batch, so `discount_laari` (promised at submit)
 * and `discount_posted_laari` (what reached the ledger) diverge the first
 * time a merchant underpays. Reporting the promised figure claimed relief
 * the ledger never granted and — because the discount is netted off what a
 * line still owes — pushed the last allocated line's Collected above its own
 * Gross due, while the same month's ledger-derived earnings report said the
 * discount was zero.
 */
it('never reports a prompt discount the ledger withheld on a part-paid batch', function () {
    $fixture = ReportFixture::payable([100_000, 50_000, 200_000, 80_000], discountRateBp: 1000);

    // 4,125 covers the first two lines exactly and leaves two behind.
    $settlement = $fixture->payAndMatch($fixture->submit(), 4_125);

    expect($settlement->state)->toBe(SettlementState::PartiallySettled)
        ->and($settlement->discount_laari)->toBe(323)
        ->and($settlement->discount_posted_laari)->toBe(0);

    $report = augustReport();
    $sheet = $report->sheet(CashbackReport::TRANSACTIONS);
    $grossIndex = $sheet->indexOf('gross_due_laari');
    $discountIndex = $sheet->indexOf('discount_laari');
    $collectedIndex = $sheet->indexOf('collected_laari');

    foreach ($sheet->rows() as $row) {
        expect($row[$discountIndex])->toBe(0);

        // No line can ever collect more than it was due.
        if ($row[$collectedIndex] !== null) {
            expect($row[$collectedIndex])->toBeLessThanOrEqual($row[$grossIndex]);
        }
    }

    // And the two reports agree about the one fact.
    expect($report->summary()['transactions']['discount_laari'])
        ->toBe(0)
        ->toBe((new EarningsReport(ReportPeriod::of('2026-08-01', '2026-08-31')))->summary()['prompt_discounts_laari'])
        // The column still sums to the cash that arrived.
        ->and($report->summary()['transactions']['collected_laari'])->toBe(4_125);
});
