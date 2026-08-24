<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Turns a report's sheets into a workbook somebody can actually work in.
 *
 * The properties this file lives or dies by — the same ones
 * TransferSheetExporter learned the hard way:
 *
 *   - MONEY IS A NUMBER. Laari over a hundred, written numerically with a
 *     '#,##0.00' format. A formatted string looks identical on screen and
 *     sums to nothing, which is the single most expensive kind of bug a
 *     finance sheet can have.
 *   - PERCENTS ARE NUMBERS TOO. Basis points over ten thousand under a
 *     '0.00%' format, so 200bp reads 2.00% and can be multiplied.
 *   - DATES ARE DATES. Excel serials, built from a BUSINESS-time instant, so
 *     sorting and filtering by date work and nobody re-reads a timezone.
 *   - EVERY TEXT CELL IS WRITTEN AS A STRING, which is what keeps a value
 *     beginning = + - or @ from being evaluated as a formula when the file
 *     is opened.
 *
 * On top of that: the header row is frozen and bold, an autofilter covers
 * the header and its data (never the totals row, which must not be filtered
 * away), and a totals row closes each sheet with real =SUM() formulas so a
 * reader can widen the maths themselves.
 *
 * A sheet may open with a HeaderBlock — the report's name, the period, the
 * filters and the money-direction glossary. It is rendered here and NOWHERE
 * else: the block never enters Sheet::$rows, so the preview cannot serialise
 * it and no totals formula can reach it. Everything below it is offset by
 * $headerRow, which is the single number the frozen pane, the autofilter
 * range and every SUM range are computed from.
 */
final class XlsxWriter
{
    /** Excel's own limit on a worksheet name. */
    private const int MAX_TITLE = 31;

    private const string MONEY_FORMAT = '#,##0.00';

    private const string PERCENT_FORMAT = '0.00%';

    private const string DATE_FORMAT = 'yyyy-mm-dd hh:mm';

    public function __construct(private readonly string $timezone)
    {
        //
    }

    public static function forBusinessTime(): self
    {
        return new self(ReportPeriod::businessTimezone());
    }

    /**
     * Writes the workbook to a temporary file and returns its path. The
     * caller owns the file — the download response deletes it after send.
     *
     * @param  list<Sheet>  $sheets  in workbook order (Summary first)
     */
    public function write(array $sheets): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $used = [];

        foreach ($sheets as $index => $sheet) {
            $worksheet = $spreadsheet->createSheet($index);
            $worksheet->setTitle($this->uniqueTitle($sheet->title, $used));
            $this->render($worksheet, $sheet);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $path = (string) tempnam(sys_get_temp_dir(), 'manfaa-report-');

        // The file is owned by the function that CREATES it, not by the
        // caller that receives the path — because between tempnam() and the
        // return there is no path to hand back, so a throw here would leave
        // the caller's own finally with nothing to unlink. It would leave a
        // partial workbook of full customer names and whole bank account
        // numbers in the temp dir instead, permanently, with nothing that
        // reaps them. That is not hypothetical: it is exactly the failure
        // ReportTooLargeException::MAX_EXPORT_ROWS was measured against,
        // where the writer dies inside zipstream part-way through.
        try {
            (new Xlsx($spreadsheet))->save($path);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $path;
    }

    private function render(Worksheet $worksheet, Sheet $sheet): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($sheet->columns)));

        // EVERY row coordinate below is derived from this one number. A
        // sheet with no header block offsets by zero and lands exactly where
        // it always did (labels on row 1, data from row 2); a sheet with one
        // pushes the whole structure — labels, frozen pane, autofilter and
        // the SUM ranges — down together. Deriving them all from
        // $headerRow rather than writing 1s and 2s is what stops a totals
        // formula from quietly summing the glossary.
        $headerRow = ($sheet->header?->height() ?? 0) + 1;
        $firstDataRow = $headerRow + 1;

        if ($sheet->header !== null) {
            $this->renderHeaderBlock($worksheet, $sheet->header);
        }

        foreach ($sheet->columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);

            $worksheet->setCellValueExplicit($letter.$headerRow, $column->label, DataType::TYPE_STRING);
            $worksheet->getColumnDimension($letter)->setWidth(
                max($column->type->width(), mb_strlen($column->label) + 3),
            );
        }

        $worksheet->getStyle('A'.$headerRow.':'.$lastColumn.$headerRow)->getFont()->setBold(true);

        // Frozen header: the labels stay put however far finance scrolls.
        //
        // Only on a sheet WITHOUT a header block. A frozen pane in Excel
        // pins everything above the split — there is no way to pin row 12
        // while rows 1–11 scroll — so freezing the label row of a
        // block-bearing sheet would nail the whole eleven-line preamble to
        // the top of the window, on the sheet the workbook opens on. The
        // block is worth reading once, not worth a third of the viewport
        // forever. The sheets that carry a block are the Summary sheets,
        // which are a few dozen rows and never need a pinned header;
        // Transactions, Settlements, Payouts and Postings are the long ones
        // and they have no block, so they freeze exactly as they always did.
        if ($sheet->header === null) {
            $worksheet->freezePane('A'.$firstDataRow);
        }

        $row = $headerRow;

        foreach ($sheet->rows() as $values) {
            $row++;

            foreach ($sheet->columns as $index => $column) {
                $this->writeCell(
                    $worksheet,
                    Coordinate::stringFromColumnIndex($index + 1).$row,
                    $column->type,
                    $values[$index] ?? null,
                );
            }
        }

        $lastDataRow = $row;

        // The filter covers the header and the data only. Including the
        // totals row would let a filter hide the total, or worse, leave a
        // total showing that no longer matches what is on screen. It must
        // not reach the header block either: an autofilter starting at row 1
        // would put dropdown arrows on the glossary and offer "Period" as a
        // filterable value.
        $worksheet->setAutoFilter('A'.$headerRow.':'.$lastColumn.$lastDataRow);

        if ($sheet->totals === []) {
            return;
        }

        $totalsRow = $lastDataRow + 1;
        $worksheet->setCellValueExplicit('A'.$totalsRow, 'Total', DataType::TYPE_STRING);

        foreach ($sheet->totals as $key) {
            $index = $sheet->indexOf($key);

            if ($index === null) {
                continue;
            }

            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $column = $sheet->columns[$index];

            // A real formula, not a precomputed number: a reader who
            // deletes a row gets a total that follows.
            $worksheet->setCellValue(
                $letter.$totalsRow,
                $lastDataRow >= $firstDataRow
                    ? sprintf('=SUM(%1$s%2$d:%1$s%3$d)', $letter, $firstDataRow, $lastDataRow)
                    : 0,
            );

            if ($column->type === ColumnType::Money) {
                $worksheet->getStyle($letter.$totalsRow)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            }
        }

        $worksheet->getStyle('A'.$totalsRow.':'.$lastColumn.$totalsRow)->getFont()->setBold(true);
    }

    /**
     * The prose above the column header: the report's name, the facts that
     * define this render, and the money-direction glossary.
     *
     * Written as explicit strings, like every other text cell, so a note
     * that begins with a "-" is prose rather than a formula. Nothing here
     * is merged: merged cells break sorting and copy-paste for the reader,
     * and a long sentence in column A already overflows readably across the
     * empty cells beside it.
     */
    private function renderHeaderBlock(Worksheet $worksheet, HeaderBlock $header): void
    {
        foreach ($header->lines() as $index => [$label, $value]) {
            $row = $index + 1;

            if ($label !== '') {
                $worksheet->setCellValueExplicit('A'.$row, $label, DataType::TYPE_STRING);
            }

            if ($value !== '') {
                $worksheet->setCellValueExplicit('B'.$row, $value, DataType::TYPE_STRING);
            }
        }

        $worksheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        if ($header->factCount() > 0) {
            $worksheet->getStyle('A2:A'.($header->factCount() + 1))->getFont()->setBold(true);
        }
    }

    private function writeCell(Worksheet $worksheet, string $coordinate, ColumnType $type, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        match ($type) {
            ColumnType::Money => $this->numeric($worksheet, $coordinate, ((int) $value) / 100, self::MONEY_FORMAT),
            ColumnType::Percent => $this->numeric($worksheet, $coordinate, ((int) $value) / 10000, self::PERCENT_FORMAT),
            ColumnType::Int => $worksheet->setCellValueExplicit($coordinate, (int) $value, DataType::TYPE_NUMERIC),
            ColumnType::Date => $this->date($worksheet, $coordinate, $value),
            ColumnType::Text => $worksheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING),
        };
    }

    private function numeric(Worksheet $worksheet, string $coordinate, float $value, string $format): void
    {
        $worksheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
        $worksheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
    }

    private function date(Worksheet $worksheet, string $coordinate, mixed $value): void
    {
        $instant = $value instanceof CarbonImmutable
            ? $value
            : CarbonImmutable::parse((string) $value);

        // Excel has no timezone: a serial is a wall clock. Converting first
        // is what makes the cell say the hour the Maldives saw.
        $worksheet->setCellValueExplicit(
            $coordinate,
            ExcelDate::PHPToExcel($instant->setTimezone($this->timezone)->toDateTime()),
            DataType::TYPE_NUMERIC,
        );

        $worksheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
    }

    /**
     * A worksheet name Excel will accept: at most 31 characters, none of
     * the reserved ones, and never a duplicate within one workbook.
     *
     * @param  list<string>  $used
     */
    private function uniqueTitle(string $title, array &$used): string
    {
        $clean = trim(str_replace(['\\', '/', '?', '*', ':', '[', ']'], ' ', $title));
        $clean = $clean === '' ? 'Sheet' : mb_substr($clean, 0, self::MAX_TITLE);

        $candidate = $clean;
        $suffix = 2;

        while (in_array($candidate, $used, true)) {
            $tail = ' '.$suffix++;
            $candidate = mb_substr($clean, 0, self::MAX_TITLE - mb_strlen($tail)).$tail;
        }

        $used[] = $candidate;

        return $candidate;
    }
}
