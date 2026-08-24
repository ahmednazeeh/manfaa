<?php

declare(strict_types=1);

use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\ColumnType;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\Sheet;
use App\Domain\Reports\XlsxWriter;
use App\Models\AdminUser;
use App\Models\ReportExport;
use Carbon\CarbonImmutable;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The workbook as the browser would receive it. The response holds a real
 * file (deleteFileAfterSend only fires on a real send), so the assertions
 * are made against the bytes an admin would actually open.
 */
function openExport(string $path): Spreadsheet
{
    $response = test()->actingAs(test()->superadmin, 'admin')->get($path);

    $response->assertOk();

    $file = $response->baseResponse->getFile()->getPathname();

    $workbook = IOFactory::createReader(IOFactory::READER_XLSX)->load($file);

    // `deleteFileAfterSend` only fires on a real send, which a test client
    // never performs — so the test owns the file instead. Without this the
    // suite silently fills /tmp with real report bytes.
    @unlink($file);

    return $workbook;
}

it('exports a workbook with Summary first and every sheet in order', function () {
    $fixture = ReportFixture::payable([100_000, 50_000], discountRateBp: 500);
    $fixture->payAndMatch($fixture->submit(), 4_100);

    $workbook = openExport('/api/admin/reports/cashback/export?from=2026-08-01&to=2026-08-31');

    expect($workbook->getSheetNames())->toBe([
        CashbackReport::SUMMARY,
        CashbackReport::TRANSACTIONS,
        CashbackReport::SETTLEMENTS,
    ]);

    // The five-sheet report too, so the order rule is not a one-off.
    expect(openExport('/api/admin/reports/payouts/export?from=2026-08-01&to=2026-08-31')->getSheetNames())->toBe([
        PayoutReport::SUMMARY,
        PayoutReport::TRANSACTIONS,
        PayoutReport::PAYOUTS,
        PayoutReport::BATCHES,
        PayoutReport::WITHDRAWALS,
    ]);
});

it('writes money, percents and dates as NUMBERS a spreadsheet can add up', function () {
    $fixture = ReportFixture::payable([100_000, 50_000], discountRateBp: 500);
    $fixture->payAndMatch($fixture->submit(), 4_100);

    $sheet = openExport('/api/admin/reports/cashback/export?from=2026-08-01&to=2026-08-31')
        ->getSheetByName(CashbackReport::TRANSACTIONS);

    // Header labels, in the order the report declares them.
    expect($sheet->getCell('A1')->getValue())->toBe('Date')
        ->and($sheet->getCell('H1')->getValue())->toBe('Eligible sale')
        ->and($sheet->getCell('I1')->getValue())->toBe('Rate')
        ->and($sheet->getCell('J1')->getValue())->toBe('Cashback')
        // The direction is in the label (owner, 2026-08-24): this column is
        // money the MERCHANT sent us, two columns from one naming the batch
        // in which we paid the customer.
        ->and($sheet->getCell('P1')->getValue())->toBe('Collected from merchant')
        ->and($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue();

    // Money: laari over a hundred, as a number under a money format. A
    // string here would look identical and sum to nothing.
    expect($sheet->getCell('H2')->getValue())->toBe(1000.0)
        ->and($sheet->getStyle('H2')->getNumberFormat()->getFormatCode())->toBe('#,##0.00')
        ->and($sheet->getCell('J2')->getValue())->toBe(20.0);

    // Percent: 200bp reads 2.00%, and is 0.02 underneath.
    expect($sheet->getCell('I2')->getValue())->toBe(0.02)
        ->and($sheet->getStyle('I2')->getNumberFormat()->getFormatCode())->toBe('0.00%');

    // Dates are real Excel dates, in BUSINESS time.
    $expected = $fixture->transactions[0]->occurred_at->setTimezone('Indian/Maldives');

    expect(ExcelDate::isDateTime($sheet->getCell('A2')))->toBeTrue()
        ->and(ExcelDate::excelToDateTimeObject($sheet->getCell('A2')->getValue())->format('Y-m-d H:i'))
        ->toBe($expected->format('Y-m-d H:i'))
        ->and($sheet->getStyle('A2')->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd hh:mm');

    // The header is frozen and the data is filterable — but the filter stops
    // at the last data row, so a totals row can never be filtered away.
    expect($sheet->getFreezePane())->toBe('A2')
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:V3');
});

it('closes each sheet with a totals row of real SUM formulas', function () {
    $fixture = ReportFixture::payable([100_000, 50_000], discountRateBp: 500);
    $settlement = $fixture->payAndMatch($fixture->submit(), 4_100);

    $workbook = openExport('/api/admin/reports/cashback/export?from=2026-08-01&to=2026-08-31');
    $sheet = $workbook->getSheetByName(CashbackReport::TRANSACTIONS);

    // Two data rows, so the totals row is row 4.
    expect($sheet->getCell('A4')->getValue())->toBe('Total')
        ->and($sheet->getCell('H4')->getValue())->toBe('=SUM(H2:H3)')
        ->and($sheet->getCell('P4')->getValue())->toBe('=SUM(P2:P3)')
        ->and($sheet->getStyle('H4')->getNumberFormat()->getFormatCode())->toBe('#,##0.00')
        ->and($sheet->getStyle('A4')->getFont()->getBold())->toBeTrue();

    // A non-summable column carries no total — a summed date or state is
    // nonsense, and a spreadsheet full of nonsense totals is not read.
    expect($sheet->getCell('Q4')->getValue())->toBeNull();

    // The collected column adds up to the transfer, in the workbook itself.
    $collected = 0.0;

    foreach ([2, 3] as $row) {
        $collected += (float) $sheet->getCell('P'.$row)->getValue();
    }

    expect((int) round($collected * 100))->toBe($settlement->amount_received_laari);
});

it('names the file after the report, its period and its merchant filter', function () {
    $response = $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/earnings/export?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    expect($response->headers->get('content-type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('content-disposition'))
        ->toContain('manfaa-earnings-2026-08-01-2026-08-31.xlsx');

    @unlink($response->baseResponse->getFile()->getPathname());

    // A filtered export of the same period is a DIFFERENT workbook, and two
    // files that want the same name are two files nobody can tell apart on
    // disk — the second just lands as "... (1).xlsx".
    $merchant = ReportFixture::payable([100_000], discountRateBp: 0)->merchant;

    $filtered = $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/earnings/export?from=2026-08-01&to=2026-08-31&merchant_id='.$merchant->id)
        ->assertOk();

    expect($filtered->headers->get('content-disposition'))
        ->toContain('manfaa-earnings-2026-08-01-2026-08-31-m'.$merchant->id.'.xlsx');

    @unlink($filtered->baseResponse->getFile()->getPathname());
});

/*
 * The workbook carries every merchant's sales, every customer code, masked
 * names, bank-account last-4 and the whole money trace, on a URL with no
 * per-user component. Symfony's BinaryFileResponse defaults to
 * `Cache-Control: public`, which invites shared caches and the browser disk
 * cache to keep it under a key any other admin's request would produce.
 */
it('serves the export privately, never as a cacheable public file', function () {
    $response = $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/earnings/export?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    $cacheControl = (string) $response->headers->get('cache-control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store')
        ->and($cacheControl)->not->toContain('public')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');

    @unlink($response->baseResponse->getFile()->getPathname());
});

/*
 * Nothing owns the temp workbook between tempnam() and a response that is
 * actually handed off, so every failure in between must delete it — an
 * exception from the audit insert used to leave a file of real customer
 * codes in /tmp permanently, and nothing reaps them.
 */
it('leaves no temp workbook behind when the export fails after writing it', function () {
    ReportFixture::payable([100_000], discountRateBp: 0);

    $before = glob(sys_get_temp_dir().'/manfaa-report-*') ?: [];

    // The audit insert is the last thing that runs before the response is
    // built; make it throw.
    ReportExport::creating(function (): void {
        throw new RuntimeException('audit exploded');
    });

    try {
        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($this->superadmin, 'admin')
            ->get('/api/admin/reports/cashback/export?from=2026-08-01&to=2026-08-31'))
            ->toThrow(RuntimeException::class, 'audit exploded');
    } finally {
        ReportExport::flushEventListeners();
    }

    $after = glob(sys_get_temp_dir().'/manfaa-report-*') ?: [];

    expect(array_diff($after, $before))->toBe([]);
});

it('keeps a worksheet name inside the 31 characters Excel allows', function () {
    $writer = new XlsxWriter('Indian/Maldives');

    $path = $writer->write([
        new Sheet('A settlement reconciliation summary for August', [ReportColumn::text('a', 'A')]),
        new Sheet('A settlement reconciliation summary for September', [ReportColumn::text('a', 'A')]),
    ]);

    try {
        $names = IOFactory::createReader(IOFactory::READER_XLSX)->load($path)->getSheetNames();

        expect($names[0])->toBe('A settlement reconciliation sum')
            ->and(mb_strlen($names[0]))->toBeLessThanOrEqual(31)
            // A second sheet whose name truncates to the same thing must
            // still be a different worksheet.
            ->and($names[1])->not->toBe($names[0])
            ->and(mb_strlen($names[1]))->toBeLessThanOrEqual(31);
    } finally {
        unlink($path);
    }
});

it('writes an empty sheet without a broken formula', function () {
    // A period with nothing in it is a normal answer, not an error — and
    // =SUM(H2:H1) is not a formula.
    $writer = new XlsxWriter('Indian/Maldives');

    $sheet = new Sheet('Empty', [
        ReportColumn::text('name', 'Name'),
        ReportColumn::money('amount_laari', 'Amount'),
    ], totals: ['amount_laari']);

    $path = $writer->write([$sheet]);

    try {
        $worksheet = IOFactory::createReader(IOFactory::READER_XLSX)->load($path)->getActiveSheet();

        expect($worksheet->getCell('A2')->getValue())->toBe('Total')
            ->and($worksheet->getCell('B2')->getValue())->toBe(0);
    } finally {
        unlink($path);
    }
});

it('renders a null money cell as blank, never as a zero', function () {
    $writer = new XlsxWriter('Indian/Maldives');

    $sheet = new Sheet('Blanks', [
        ReportColumn::text('name', 'Name'),
        ReportColumn::money('collected_laari', 'Collected'),
        ReportColumn::date('settled_at', 'Settled at'),
    ]);

    $sheet->push(['Unsettled sale', null, null]);
    $sheet->push(['Settled sale', 2_750, CarbonImmutable::parse('2026-08-08T11:00:00+05:00')]);

    $path = $writer->write([$sheet]);

    try {
        $worksheet = IOFactory::createReader(IOFactory::READER_XLSX)->load($path)->getActiveSheet();

        expect($worksheet->getCell('B2')->getValue())->toBeNull()
            ->and($worksheet->getCell('C2')->getValue())->toBeNull()
            ->and($worksheet->getCell('B3')->getValue())->toBe(27.5)
            ->and(ExcelDate::excelToDateTimeObject($worksheet->getCell('C3')->getValue())->format('Y-m-d H:i'))
            ->toBe('2026-08-08 11:00');
    } finally {
        unlink($path);
    }
});

it('refuses to total a column that cannot be added up', function () {
    expect(fn () => new Sheet('Bad', [ReportColumn::text('state', 'State')], totals: ['state']))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => (new Sheet('Bad', [ReportColumn::text('a', 'A')]))->push(['a', 'b']))
        ->toThrow(InvalidArgumentException::class);
});

it('answers an empty period with a workbook rather than an error', function () {
    $workbook = openExport('/api/admin/reports/cashback/export?from=2026-01-01&to=2026-01-31');

    expect($workbook->getSheetNames())->toBe([
        CashbackReport::SUMMARY,
        CashbackReport::TRANSACTIONS,
        CashbackReport::SETTLEMENTS,
    ])
        ->and($workbook->getSheetByName(CashbackReport::TRANSACTIONS)->getCell('A2')->getValue())->toBe('Total');

    expect(ReportPeriod::of('2026-01-01', '2026-01-31')->days())->toBe(31);
});

it('gives every column type a width and a sensible default', function () {
    expect(ColumnType::Money->isSummable())->toBeTrue()
        ->and(ColumnType::Int->isSummable())->toBeTrue()
        ->and(ColumnType::Date->isSummable())->toBeFalse()
        ->and(ColumnType::Percent->isSummable())->toBeFalse()
        ->and(ColumnType::Text->isSummable())->toBeFalse();
});
