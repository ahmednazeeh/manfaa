<?php

declare(strict_types=1);

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\ManualCreditService;
use App\Domain\Cashback\TransitionService;
use App\Domain\Money\Laari;
use App\Domain\Payout\ApprovalService;
use App\Domain\Payout\BankFileExporter;
use App\Domain\Payout\PayoutBatchBuilder;
use App\Domain\Payout\PayoutItemSettler;
use App\Domain\Reports\ColumnType;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\Sheet;
use App\Domain\Reports\SheetGrouping;
use App\Domain\Reports\XlsxWriter;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A BATCH READS AS ONE BLOCK (owner, 2026-08-24).
 *
 * One payout batch pays many customers, so its reference used to repeat down
 * the whole column and forty rows of one transfer run looked like forty
 * unrelated payments. The reference is now printed ONCE, on the first row of
 * its batch, and the rows are ordered by batch and then by customer within
 * it.
 *
 * The three properties that make that safe rather than merely pretty, all
 * asserted below:
 *
 *   - the blanked cell is a REAL BLANK, never a space (a space sorts, filters
 *     and defeats COUNTBLANK while looking identical on screen);
 *   - a plain VISIBLE `Batch key` column carries the batch on every row, so
 *     an autofilter still catches all of it — the filter reads the cells that
 *     are there, and a hidden column would be invisible to the reader who
 *     needs it;
 *   - the JSON PREVIEW keeps every row fully populated. The blanking is a
 *     workbook presentation rule and lives only in XlsxWriter; the preview is
 *     data a machine reads.
 */
beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-20T12:00:00+05:00'));

    $this->seed(LedgerAccountSeeder::class);

    $this->admin = AdminUser::factory()->create();
    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
    $this->merchant = Merchant::factory()->create([
        'name' => 'Sea House Cafe',
        'validation_window_days' => 3,
        'min_eligible_laari' => 5000,
    ]);
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->creditor = MerchantUser::factory()->for($this->merchant)->owner()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A credit walked all the way to confirmed through the real services. */
function groupedConfirm(Customer $customer, int $eligibleLaari): Transaction
{
    $transitions = app(TransitionService::class);

    $transaction = app(ManualCreditService::class)->credit(
        test()->merchant,
        test()->creditor,
        $customer->customer_code,
        'INV-'.Str::upper(Str::random(10)),
        Laari::of($eligibleLaari),
        null,
        CarbonImmutable::now('UTC')->subHour(),
    );

    $transitions->makePayable($transaction, Actor::system());
    $transitions->confirm($transaction, Actor::system());

    return $transaction->refresh();
}

/** Build, approve, export and pay every item of one batch. */
function groupedBatchPaid(string $trxPrefix): void
{
    $batch = app(PayoutBatchBuilder::class)->buildDraft(CarbonImmutable::now('UTC'), test()->admin);
    app(ApprovalService::class)->approve($batch, test()->admin);
    app(BankFileExporter::class)->export($batch->refresh());

    $settler = app(PayoutItemSettler::class);

    foreach ($batch->items as $index => $item) {
        $settler->settleOne($item, $trxPrefix.'-'.$index);
    }
}

/** Two paid batches of two customers each, in August. */
function twoPaidBatches(): void
{
    // Batch one, on the 20th.
    groupedConfirm(ReportFixture::customer('Aminath Shifa'), 500_000);
    groupedConfirm(ReportFixture::customer('Hawwa Latheefa'), 600_000);
    groupedBatchPaid('BML-A');

    // Batch two, a day later — a distinct reference, so the two sort apart.
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-21T12:00:00+05:00'));

    groupedConfirm(ReportFixture::customer('Mariyam Zulfa'), 700_000);
    groupedConfirm(ReportFixture::customer('Fathimath Nahula'), 800_000);
    groupedBatchPaid('BML-B');
}

it('orders the payouts sheet by batch, then by customer within the batch', function () {
    twoPaidBatches();

    $payouts = (new PayoutReport(ReportPeriod::of('2026-08-01', '2026-08-31')))
        ->sheet(PayoutReport::PAYOUTS);

    $refIndex = $payouts->indexOf('batch_ref');
    $keyIndex = $payouts->indexOf('batch_id');
    $codeIndex = $payouts->indexOf('customer_code');

    expect($payouts->count())->toBe(4)
        ->and($keyIndex)->not->toBeNull();

    $refs = array_map(fn (array $row) => $row[$refIndex], $payouts->rows());
    $keys = array_map(fn (array $row) => $row[$keyIndex], $payouts->rows());
    $codes = array_map(fn (array $row) => $row[$codeIndex], $payouts->rows());

    // Batches contiguous, in reference order.
    expect($refs)->toBe([$refs[0], $refs[0], $refs[2], $refs[2]])
        ->and($refs[0])->not->toBe($refs[2])
        ->and($refs[0] < $refs[2])->toBeTrue()
        ->and($keys[0])->toBe($keys[1])
        ->and($keys[2])->toBe($keys[3])
        ->and($keys[0])->not->toBe($keys[2]);

    // Customers ascending WITHIN each batch, not across the sheet.
    expect($codes[0] < $codes[1])->toBeTrue()
        ->and($codes[2] < $codes[3])->toBeTrue();
});

it('keeps every preview row fully populated — the grouping never reaches the data', function () {
    twoPaidBatches();

    $payouts = (new PayoutReport(ReportPeriod::of('2026-08-01', '2026-08-31')))
        ->sheet(PayoutReport::PAYOUTS);

    $refIndex = $payouts->indexOf('batch_ref');
    $keyIndex = $payouts->indexOf('batch_id');

    foreach ($payouts->previewRows(50) as $row) {
        expect($row[$refIndex])->not->toBe('')
            ->and($row[$refIndex])->not->toBeNull()
            ->and($row[$keyIndex])->toBeGreaterThan(0);
    }

    // And the stored rows themselves, which the preview serialises.
    foreach ($payouts->rows() as $row) {
        expect((string) $row[$refIndex])->not->toBe('');
    }

    // The machine key is a real column in the JSON column metadata, not a
    // hidden one — an autofilter can only catch what it can see.
    expect(collect($payouts->columnMeta())->firstWhere('key', 'batch_id'))
        ->toBe(['key' => 'batch_id', 'label' => 'Batch key', 'type' => 'int']);
});

it('carries the same join key on the Batches sheet, so the two sheets meet', function () {
    twoPaidBatches();

    $report = new PayoutReport(ReportPeriod::of('2026-08-01', '2026-08-31'));
    $payouts = $report->sheet(PayoutReport::PAYOUTS);
    $batches = $report->sheet(PayoutReport::BATCHES);

    // A key that lives on one sheet only joins to nothing: a reader who
    // filters Payouts by merchant or bank loses the printed-once reference
    // and is left holding this integer.
    expect(collect($batches->columnMeta())->firstWhere('key', 'batch_id'))
        ->toBe(['key' => 'batch_id', 'label' => 'Batch key', 'type' => 'int']);

    $payoutKeys = array_unique(array_map(
        fn (array $row) => $row[$payouts->indexOf('batch_id')],
        $payouts->rows(),
    ));
    $batchKeys = array_map(
        fn (array $row) => $row[$batches->indexOf('batch_id')],
        $batches->rows(),
    );

    sort($payoutKeys);
    sort($batchKeys);

    expect($batchKeys)->toBe(array_values($payoutKeys));
});

it('prints the batch reference once per block in the workbook, as a real blank', function () {
    twoPaidBatches();

    $response = $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/payouts/export?from=2026-08-01&to=2026-08-31');

    $response->assertOk();

    $file = $response->baseResponse->getFile()->getPathname();
    $workbook = IOFactory::createReader(IOFactory::READER_XLSX)->load($file);
    @unlink($file);

    $worksheet = $workbook->getSheetByName(PayoutReport::PAYOUTS);

    // Column A is the reference, column B the machine key; row 1 is the
    // header, so data starts at row 2 (this sheet carries no header block).
    expect($worksheet->getCell('A1')->getValue())->toBe('Payout batch ref')
        ->and($worksheet->getCell('B1')->getValue())->toBe('Batch key');

    $first = (string) $worksheet->getCell('A2')->getValue();
    $second = (string) $worksheet->getCell('A4')->getValue();

    expect($first)->not->toBe('')
        ->and($second)->not->toBe('')
        ->and($first)->not->toBe($second);

    // The SECOND row of each block is empty — and empty means NULL, not a
    // space and not an empty string that was actually written.
    expect($worksheet->getCell('A3')->getValue())->toBeNull()
        ->and($worksheet->getCell('A5')->getValue())->toBeNull();

    // The machine key is on EVERY row, including the ones whose label was
    // blanked: that is what keeps an Excel filter honest.
    foreach (['B2', 'B3', 'B4', 'B5'] as $coordinate) {
        expect($worksheet->getCell($coordinate)->getValue())->toBeGreaterThan(0);
    }

    expect($worksheet->getCell('B2')->getValue())->toBe($worksheet->getCell('B3')->getValue())
        ->and($worksheet->getCell('B4')->getValue())->toBe($worksheet->getCell('B5')->getValue())
        ->and($worksheet->getCell('B2')->getValue())->not->toBe($worksheet->getCell('B4')->getValue());
});

it('restarts a block on a new key even when two batches share a reference', function () {
    // A cancelled batch keeps its reference, so production really does hold
    // several rows with the same one. The grouping compares the KEY, never
    // the printed label — collapsing two different batches into one block
    // would state something false about the money.
    $sheet = new Sheet(
        'Payouts',
        [
            ReportColumn::text('batch_ref', 'Payout batch ref'),
            ReportColumn::int('batch_id', 'Batch key'),
            ReportColumn::money('amount_laari', 'Payout amount'),
        ],
        grouping: new SheetGrouping(keyColumn: 'batch_id', labelColumn: 'batch_ref'),
    );

    $sheet->push(['PB-20260816', 41, 1000]);
    $sheet->push(['PB-20260816', 41, 2000]);
    // Same printed reference, DIFFERENT batch.
    $sheet->push(['PB-20260816', 42, 3000]);

    $path = (new XlsxWriter('Indian/Maldives'))->write([$sheet]);
    $worksheet = IOFactory::createReader(IOFactory::READER_XLSX)->load($path)->getSheetByName('Payouts');
    @unlink($path);

    expect($worksheet->getCell('A2')->getValue())->toBe('PB-20260816')
        ->and($worksheet->getCell('A3')->getValue())->toBeNull()
        // The new batch prints its reference again, identical string or not.
        ->and($worksheet->getCell('A4')->getValue())->toBe('PB-20260816');
});

it('refuses a grouping that would blank the column it groups on', function () {
    expect(fn () => new Sheet(
        'Broken',
        [ReportColumn::text('batch_ref', 'Ref')],
        grouping: new SheetGrouping('batch_ref', 'batch_ref'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new Sheet(
        'Broken',
        [ReportColumn::text('batch_ref', 'Ref')],
        grouping: new SheetGrouping('nope', 'batch_ref'),
    ))->toThrow(InvalidArgumentException::class);
});

it('leaves an ungrouped sheet writing every cell exactly as it always did', function () {
    $sheet = new Sheet('Plain', [
        ReportColumn::text('ref', 'Ref'),
        ReportColumn::money('amount_laari', 'Amount'),
    ]);

    $sheet->push(['PB-1', 100]);
    $sheet->push(['PB-1', 200]);

    $path = (new XlsxWriter('Indian/Maldives'))->write([$sheet]);
    $worksheet = IOFactory::createReader(IOFactory::READER_XLSX)->load($path)->getSheetByName('Plain');
    @unlink($path);

    expect($worksheet->getCell('A2')->getValue())->toBe('PB-1')
        ->and($worksheet->getCell('A3')->getValue())->toBe('PB-1')
        ->and(ColumnType::Money->isSummable())->toBeTrue();
});
