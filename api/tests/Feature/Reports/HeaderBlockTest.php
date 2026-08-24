<?php

declare(strict_types=1);

use App\Domain\Reports\CashbackReport;
use App\Domain\Reports\ColumnType;
use App\Domain\Reports\EarningsReport;
use App\Domain\Reports\HeaderBlock;
use App\Domain\Reports\PayoutReport;
use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportOptions;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\Sheet;
use App\Domain\Reports\XlsxWriter;
use App\Models\AdminUser;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Reports\ReportFixture;
use Tests\TestCase;

/**
 * REFINEMENT 3 (owner, 2026-08-24): MONEY DIRECTION MUST BE UNAMBIGUOUS.
 *
 * "For a tax pro, 'settlement' is confusing — is it the merchant settling to
 * us, or our payout to the customer?" So the sheet titles carry the
 * direction, the ambiguous column labels carry it, and every Summary sheet
 * opens with a header block naming both flows in full.
 *
 * The header block pushes every row of the sheet down, which makes the
 * frozen pane, the autofilter range and the =SUM() ranges the easiest thing
 * in this round to break — and all three are invisible in a passing
 * assertion about cell values. So the structural half of this file REOPENS
 * the written workbook with PhpSpreadsheet and reads them back.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    $this->superadmin = AdminUser::factory()->create(['role' => 'superadmin']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Writes sheets to a real .xlsx and reads them back in. */
function roundTrip(array $sheets): Spreadsheet
{
    $path = XlsxWriter::forBusinessTime()->write($sheets);

    $workbook = IOFactory::createReader(IOFactory::READER_XLSX)->load($path);

    @unlink($path);

    return $workbook;
}

// ------------------------------------------------------- titles and labels

it('carries the direction in every ambiguous sheet title, inside Excel 31 characters', function () {
    $titles = [
        CashbackReport::SETTLEMENTS => 'Settlements (money in)',
        PayoutReport::PAYOUTS => 'Payouts (money out)',
        PayoutReport::BATCHES => 'Payout batches (money out)',
        PayoutReport::WITHDRAWALS => 'Wallet withdrawals (money out)',
    ];

    foreach ($titles as $actual => $expected) {
        expect($actual)->toBe($expected)
            // Excel refuses a longer name outright, and XlsxWriter would
            // silently truncate — turning "Wallet withdrawals (money out)"
            // into something that no longer says the direction at all.
            ->and(mb_strlen($actual))->toBeLessThanOrEqual(31)
            // The domain word survives, so the sheet still matches the
            // panel's own vocabulary.
            ->and($actual)->toMatch('/^(Settlements|Payouts|Payout batches|Wallet withdrawals)/');
    }
});

it('disambiguates the column labels that point in opposite directions', function () {
    $period = ReportPeriod::of('2026-08-01', '2026-08-31');

    $cashback = new CashbackReport($period);
    $transactions = $cashback->sheet(CashbackReport::TRANSACTIONS);
    $settlements = $cashback->sheet(CashbackReport::SETTLEMENTS);

    // The two directions sit four columns apart on the SAME transaction row,
    // which is exactly why the bare words were unreadable.
    expect($transactions->column('collected_laari')?->label)->toBe('Collected from merchant')
        ->and($transactions->column('settlement_ref')?->label)->toBe('Merchant settlement ref')
        ->and($transactions->column('payout_batch_ref')?->label)->toBe('Customer payout batch')
        ->and($transactions->column('paid_at')?->label)->toBe('Paid to customer at')
        ->and($settlements->column('reference')?->label)->toBe('Merchant settlement ref')
        ->and($settlements->column('amount_due_laari')?->label)->toBe('Amount due from merchant')
        ->and($settlements->column('amount_received_laari')?->label)->toBe('Received from merchant');

    $payouts = new PayoutReport($period);

    expect($payouts->sheet(PayoutReport::PAYOUTS)->column('paid_laari')?->label)->toBe('Paid out in period')
        ->and($payouts->sheet(PayoutReport::PAYOUTS)->column('amount_laari')?->label)->toBe('Payout amount')
        ->and($payouts->sheet(PayoutReport::BATCHES)->column('reference')?->label)->toBe('Payout batch ref')
        ->and($payouts->sheet(PayoutReport::TRANSACTIONS)->column('batch_ref')?->label)->toBe('Payout batch ref');

    // The KEYS are untouched — the panel reads those, and a renamed key
    // would break every column the console already renders.
    expect($transactions->indexOf('collected_laari'))->not->toBeNull()
        ->and($settlements->indexOf('amount_received_laari'))->not->toBeNull();
});

// ------------------------------------------------------------ the contents

it('opens every Summary sheet with the report name, the window and the glossary', function () {
    $period = ReportPeriod::of('2026-08-01', '2026-08-31');

    foreach ([
        'Manfaa — cashback report' => [new CashbackReport($period), 'Excluded'],
        'Manfaa — customer payout report' => [new PayoutReport($period), 'Not applicable'],
        'Manfaa — platform earnings report' => [new EarningsReport($period), 'Not applicable'],
    ] as $title => [$report, $reversedRows]) {
        $header = $report->headerBlock();

        expect($header)->toBeInstanceOf(HeaderBlock::class)
            ->and($header->title)->toBe($title);

        $facts = collect($header->facts)->pluck('value', 'label')->all();

        expect($facts['Period'])->toBe('2026-08-01 to 2026-08-31')
            ->and($facts['Timezone'])->toBe('Indian/Maldives')
            ->and($facts['Merchant'])->toBe('All merchants')
            ->and($facts['Reversed rows'])->toStartWith($reversedRows);

        // The glossary, on all three, naming both directions.
        $notes = implode(' ', $header->notes);

        expect($notes)->toContain('MERCHANT SETTLEMENT = money IN')
            ->toContain('CUSTOMER PAYOUT = money OUT');
    }
});

it('never lets the reversed-rows fact contradict the report it is printed on', function () {
    // The failure this replaces: one sentence on BaseReport, written for the
    // cashback report, printed verbatim on all three. On earnings with the
    // flag off it said "reversed sales are not counted on this report" three
    // rows above the note saying every reversal journal IS counted; on
    // payouts with the flag on it promised reversed sales "appear below,
    // with 'reversed' in their State column" on a sheet that has neither.
    $period = ReportPeriod::of('2026-08-01', '2026-08-31');

    foreach ([false, true] as $includeReversed) {
        $options = ReportOptions::of($includeReversed);

        $cashback = collect((new CashbackReport($period, null, $options))->headerBlock()->facts)
            ->pluck('value', 'label')->all();

        // The one report the flag governs says which way it was built.
        expect($cashback['Reversed rows'])
            ->toStartWith($includeReversed ? 'INCLUDED' : 'Excluded');

        foreach ([new PayoutReport($period, null, $options), new EarningsReport($period, null, $options)] as $report) {
            $header = $report->headerBlock();
            $fact = collect($header->facts)->pluck('value', 'label')->all()['Reversed rows'];

            // Same sentence whichever way the switch was left, because the
            // report holds the same rows either way — and it never points
            // at a State column these sheets do not have.
            expect($report->reversedRowsApply())->toBeFalse()
                ->and($fact)->toStartWith('Not applicable')
                ->and($fact)->not->toContain('State column')
                ->and($fact)->not->toContain('INCLUDED')
                ->and($report->primarySheet()->indexOf('state'))->toBeNull();
        }
    }
});

it('leaves the payout and earnings reports untouched by the reversed-rows flag', function () {
    $fixture = ReportFixture::payable([100_000, 50_000]);
    $fixture->payAndMatch($fixture->submit(), $fixture->dueTotal());

    $period = ReportPeriod::of('2026-08-01', '2026-08-31');

    foreach (['payouts' => PayoutReport::class, 'earnings' => EarningsReport::class] as $class) {
        $plain = new $class($period);
        $withReversed = new $class($period, null, ReportOptions::of(includeReversed: true));

        // The claim reversedRowsApply() makes, checked rather than trusted.
        expect($withReversed->rowCount())->toBe($plain->rowCount())
            ->and($withReversed->summary())->toEqual($plain->summary());
    }
});

it('names the merchant and the reversed-rows choice the render was actually built with', function () {
    $fixture = ReportFixture::payable([100_000]);

    $header = (new CashbackReport(
        ReportPeriod::of('2026-08-01', '2026-08-31'),
        $fixture->merchant->id,
        ReportOptions::of(includeReversed: true),
    ))->headerBlock();

    $facts = collect($header->facts)->pluck('value', 'label')->all();

    expect($facts['Merchant'])
        ->toBe(sprintf('%s (id %d)', $fixture->merchant->name, $fixture->merchant->id))
        ->and($facts['Reversed rows'])->toStartWith('INCLUDED');
});

it('keeps the block out of the data entirely', function () {
    $fixture = ReportFixture::payable([100_000]);
    $fixture->payAndMatch($fixture->submit(), $fixture->dueTotal());

    $report = new CashbackReport(ReportPeriod::of('2026-08-01', '2026-08-31'));
    $summary = $report->sheet(CashbackReport::SUMMARY);

    // The block is prose in column A. If it had been pushed into rows() it
    // would be counted, previewed, and summed by the totals formula.
    foreach ($summary->rows() as $row) {
        expect((string) $row[0])->not->toContain('MERCHANT SETTLEMENT')
            ->and((string) $row[0])->not->toBe('Manfaa — cashback report')
            ->and((string) $row[0])->not->toBe('Period');
    }

    // And the JSON preview carries the header as its OWN object, never among
    // the positional rows.
    $response = $this->actingAs($this->superadmin, 'admin')
        ->getJson('/api/admin/reports/cashback?from=2026-08-01&to=2026-08-31')
        ->assertOk();

    expect($response->json('header.title'))->toBe('Manfaa — cashback report')
        ->and($response->json('header.facts.0.label'))->toBe('Period')
        ->and($response->json('include_reversed'))->toBeFalse();

    foreach ($response->json('preview.rows') as $row) {
        expect($row[0])->not->toBe('Manfaa — cashback report');
    }
});

// ------------------------------------------------ the structure, reopened

it('offsets the frozen pane, the autofilter and the SUM ranges past the block', function () {
    // A sheet with a header block AND a totals row — the combination the
    // real Summary sheets do not have today, and the one where a wrong
    // offset would put a =SUM() over the glossary.
    $header = new HeaderBlock(
        title: 'Manfaa — structure probe',
        facts: [['label' => 'Period', 'value' => '2026-08-01 to 2026-08-31']],
        notes: ['MERCHANT SETTLEMENT = money IN.', 'CUSTOMER PAYOUT = money OUT.'],
    );

    $sheet = new Sheet(
        'Probe',
        [ReportColumn::text('label', 'Label'), ReportColumn::money('amount_laari', 'Amount')],
        totals: ['amount_laari'],
        header: $header,
    );

    $sheet->push(['First', 1_000]);
    $sheet->push(['Second', 2_500]);

    // title + 1 fact + blank + 2 notes + blank = 6 rows of block.
    expect($header->height())->toBe(6);

    $worksheet = roundTrip([$sheet])->getSheetByName('Probe');

    $headerRow = $header->height() + 1;      // 7 — the column labels
    $firstDataRow = $headerRow + 1;          // 8
    $lastDataRow = $headerRow + 2;           // 9
    $totalsRow = $lastDataRow + 1;           // 10

    // The block itself.
    expect($worksheet->getCell('A1')->getValue())->toBe('Manfaa — structure probe')
        ->and($worksheet->getCell('A2')->getValue())->toBe('Period')
        ->and($worksheet->getCell('B2')->getValue())->toBe('2026-08-01 to 2026-08-31')
        ->and($worksheet->getCell('A4')->getValue())->toBe('MERCHANT SETTLEMENT = money IN.')
        ->and($worksheet->getCell('A5')->getValue())->toBe('CUSTOMER PAYOUT = money OUT.');

    // The column header landed below it, bold, with the data under that.
    expect($worksheet->getCell('A'.$headerRow)->getValue())->toBe('Label')
        ->and($worksheet->getCell('B'.$headerRow)->getValue())->toBe('Amount')
        ->and($worksheet->getStyle('A'.$headerRow)->getFont()->getBold())->toBeTrue()
        ->and($worksheet->getCell('A'.$firstDataRow)->getValue())->toBe('First')
        ->and($worksheet->getCell('B'.$firstDataRow)->getValue())->toBe(10.0)
        ->and($worksheet->getCell('A'.$lastDataRow)->getValue())->toBe('Second');

    // FROZEN PANE: none on a sheet that carries a block. Excel pins
    // everything ABOVE the split, so freezing the label row here would nail
    // the whole preamble to the top of the window — on the sheet the
    // workbook opens on — rather than letting it scroll away.
    expect($worksheet->getFreezePane())->toBeNull();

    // AUTOFILTER: header through last data row. Never row 1 (which would put
    // dropdowns on the glossary) and never the totals row (which would let a
    // filter hide the total).
    expect($worksheet->getAutoFilter()->getRange())->toBe(sprintf('A%d:B%d', $headerRow, $lastDataRow));

    // SUM: over the data only, and it still evaluates to the real total.
    expect($worksheet->getCell('B'.$totalsRow)->getValue())
        ->toBe(sprintf('=SUM(B%d:B%d)', $firstDataRow, $lastDataRow))
        ->and($worksheet->getCell('A'.$totalsRow)->getValue())->toBe('Total')
        ->and((float) $worksheet->getCell('B'.$totalsRow)->getCalculatedValue())->toBe(35.0);
});

it('leaves a sheet with no header block exactly where it always was', function () {
    $sheet = new Sheet(
        'Plain',
        [ReportColumn::text('label', 'Label'), ReportColumn::money('amount_laari', 'Amount')],
        totals: ['amount_laari'],
    );

    $sheet->push(['Only', 4_200]);

    $worksheet = roundTrip([$sheet])->getSheetByName('Plain');

    // Zero offset: labels on row 1, data from row 2 — the layout every
    // detail sheet in all three reports still uses.
    expect($worksheet->getCell('A1')->getValue())->toBe('Label')
        ->and($worksheet->getFreezePane())->toBe('A2')
        ->and($worksheet->getAutoFilter()->getRange())->toBe('A1:B2')
        ->and($worksheet->getCell('B3')->getValue())->toBe('=SUM(B2:B2)');
});

it('writes an empty sheet under a header block without a broken formula', function () {
    $sheet = new Sheet(
        'Empty',
        [ReportColumn::text('label', 'Label'), ReportColumn::money('amount_laari', 'Amount')],
        totals: ['amount_laari'],
        header: new HeaderBlock(title: 'Nothing here', facts: [], notes: []),
    );

    // title + blank = 2, so the column header is row 3 and there is no data
    // row for a SUM to point at. A naive =SUM(B4:B3) is a #REF! in Excel.
    $worksheet = roundTrip([$sheet])->getSheetByName('Empty');

    expect($worksheet->getCell('A3')->getValue())->toBe('Label')
        ->and($worksheet->getCell('B4')->getValue())->toBe(0)
        ->and($worksheet->getAutoFilter()->getRange())->toBe('A3:B3');
});

it('reopens the real cashback workbook and finds the Summary intact under its block', function () {
    $fixture = ReportFixture::payable([100_000, 50_000], discountRateBp: 500);
    $fixture->payAndMatch($fixture->submit(), 4_100);

    $response = $this->actingAs($this->superadmin, 'admin')
        ->get('/api/admin/reports/cashback/export?from=2026-08-01&to=2026-08-31');

    $response->assertOk();

    $file = $response->baseResponse->getFile()->getPathname();
    $workbook = IOFactory::createReader(IOFactory::READER_XLSX)->load($file);
    @unlink($file);

    $report = new CashbackReport(ReportPeriod::of('2026-08-01', '2026-08-31'));
    $summarySheet = $report->sheet(CashbackReport::SUMMARY);
    $height = $summarySheet->header->height();

    $summary = $workbook->getSheetByName(CashbackReport::SUMMARY);

    expect($summary->getCell('A1')->getValue())->toBe('Manfaa — cashback report')
        // The column header sits directly under the block, and the first
        // data row directly under that — the rows the round shipped, in the
        // order it shipped them, simply moved down together.
        ->and($summary->getCell('A'.($height + 1))->getValue())->toBe('Metric')
        ->and($summary->getCell('A'.($height + 2))->getValue())->toBe((string) $summarySheet->rows()[0][0])
        // No frozen pane under a block: a freeze would pin all eleven lines
        // of preamble, not just the labels. The Summary sheets are a few
        // dozen rows and never needed one.
        ->and($summary->getFreezePane())->toBeNull();

    // The Transactions sheet has no block, so it is untouched — and its
    // totals row still sums from row 2.
    $transactions = $workbook->getSheetByName(CashbackReport::TRANSACTIONS);
    $rowCount = $report->sheet(CashbackReport::TRANSACTIONS)->count();

    expect($transactions->getCell('A1')->getValue())->toBe('Date')
        ->and($transactions->getFreezePane())->toBe('A2')
        ->and($transactions->getCell('H'.($rowCount + 2))->getValue())
        ->toBe(sprintf('=SUM(H2:H%d)', $rowCount + 1))
        ->and((float) $transactions->getCell('H'.($rowCount + 2))->getCalculatedValue())
        ->toBe(1500.0);
});

it('gives the header block a height that matches the rows it renders', function () {
    // height() is what every offset is derived from, so it must never be
    // computed separately from lines() — this is the drift that would put a
    // SUM over the glossary without any cell value looking wrong.
    $cases = [
        new HeaderBlock('T', [], []),
        new HeaderBlock('T', [['label' => 'A', 'value' => 'B']], []),
        new HeaderBlock('T', [], ['one']),
        new HeaderBlock('T', [['label' => 'A', 'value' => 'B']], ['one', 'two']),
    ];

    foreach ($cases as $header) {
        expect($header->height())->toBe(count($header->lines()));

        foreach ($header->lines() as $line) {
            expect($line)->toHaveCount(2);
        }
    }

    expect($cases[0]->height())->toBe(2)
        ->and($cases[1]->height())->toBe(3)
        ->and($cases[2]->height())->toBe(4)
        ->and($cases[3]->height())->toBe(6);
});

it('keeps every column type summable-or-not exactly as before the block existed', function () {
    // A guard on the one thing a header block must never change: which
    // columns a totals row is allowed to add up.
    expect(ColumnType::Money->isSummable())->toBeTrue()
        ->and(ColumnType::Int->isSummable())->toBeTrue()
        ->and(ColumnType::Text->isSummable())->toBeFalse()
        ->and(ColumnType::Date->isSummable())->toBeFalse()
        ->and(ColumnType::Percent->isSummable())->toBeFalse();
});
