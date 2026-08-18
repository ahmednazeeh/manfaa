<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Models\MerchantPayoutBatch;
use App\Models\MerchantPayoutItem;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The merchant settlement sheet — the same xlsx workflow finance already
 * uses for customer payouts (owner requirement).
 *
 * Deliberately the same shape as `TransferSheetExporter`: same column order,
 * same empty reference box at the end, same "fill it in and send it back".
 * Whoever does the transfers should not have to learn a second sheet because
 * the money happens to be going to a shop.
 */
final readonly class MerchantTransferSheet
{
    public const string KEY_HEADING = 'Payout Key';

    public const string REFERENCE_HEADING = 'Transfer Reference Number';

    /** Heading → width, in order. The order is the contract with the bank. */
    private const array COLUMNS = [
        self::KEY_HEADING => 22,
        'Merchant' => 28,
        'Account Name' => 28,
        'Account Number' => 24,
        'Amount Owed' => 14,
        self::REFERENCE_HEADING => 26,
    ];

    public function format(MerchantPayoutBatch $batch): string
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
            /** @var MerchantPayoutItem $item */
            $row++;

            // The idempotency key IS the payout key on the sheet: what comes
            // back has to be matchable to what went out, and that is the one
            // string that identifies this transfer everywhere — our table,
            // the bank's, and the paper in between.
            $sheet->setCellValueExplicit('A'.$row, (string) $item->internal_ref, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$row, (string) $item->merchant_name, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C'.$row, (string) $item->account_name, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D'.$row, (string) $item->account, DataType::TYPE_STRING);
            $sheet->setCellValue('E'.$row, $item->amount_laari / 100);
            // F is left empty on purpose: the bank's reference goes there
            // before the sheet comes back.
        }

        if ($row > 1) {
            $sheet->getStyle('E2:E'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
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
