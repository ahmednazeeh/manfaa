<?php

declare(strict_types=1);

namespace App\Domain\Payout;

use App\Models\PayoutBatch;
use App\Models\PayoutItem;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The transfer sheet finance hands to the bank: one row per payout item, in
 * the seven columns the owner asked for, with the reference column left empty
 * to be filled in and uploaded back. TransferSheetImporter reads exactly this
 * shape — one format out, the same format in.
 *
 * Two properties the sheet lives or dies by. Amount Owed is written as a
 * number, never a formatted string, or the SUM finance types under the column
 * quietly reports nothing. And every text cell is written explicitly as a
 * string, which is also what keeps an account name beginning = + - or @ from
 * being evaluated as a formula when the file is opened.
 */
final readonly class TransferSheetExporter implements BankFileFormatter
{
    public const string KEY_HEADING = 'Idempotency Key';

    public const string REFERENCE_HEADING = 'Transfer Reference Number';

    /**
     * Heading → column width, in order. The order is the contract with the
     * bank and with the importer; nothing may be slipped in between two of
     * them. Bank name is deliberately absent — the owner asked for these
     * seven, and widening a finance-facing sheet is their call, not ours.
     */
    private const array COLUMNS = [
        self::KEY_HEADING => 16,
        'Customer Name' => 28,
        'Customer Phone' => 16,
        'Customer Account Name' => 28,
        'Customer Account Number' => 24,
        'Amount Owed' => 14,
        self::REFERENCE_HEADING => 26,
    ];

    public function format(PayoutBatch $batch): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Transfers');

        $column = 1;

        foreach (self::COLUMNS as $heading => $width) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->setCellValueExplicit($letter.'1', $heading, DataType::TYPE_STRING);
            $sheet->getColumnDimension($letter)->setWidth($width);
            $column++;
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count(self::COLUMNS));
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);

        $row = 1;

        foreach ($batch->items()->orderBy('id')->get() as $item) {
            /** @var PayoutItem $item */
            $row++;

            $sheet->setCellValueExplicit('A'.$row, (string) $item->idempotency_key, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, (string) $item->customer_name, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C'.$row, (string) $item->customer_phone, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D'.$row, (string) $item->account_name, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E'.$row, (string) $item->account, DataType::TYPE_STRING);
            // Decimal MVR derived from the stored integer laari, as a number.
            $sheet->setCellValue('F'.$row, $item->amount_laari / 100);
            // G is left empty on purpose: it is the box the bank's reference
            // goes in before the sheet comes back.
        }

        if ($row > 1) {
            $sheet->getStyle('F2:F'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $writer = new Xlsx($spreadsheet);

        ob_start();

        try {
            $writer->save('php://output');
        } finally {
            $content = (string) ob_get_clean();
        }

        $spreadsheet->disconnectWorksheets();

        return $content;
    }
}
