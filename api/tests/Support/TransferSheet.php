<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Opens and edits the exported transfer sheet the way finance does, so a test
 * can assert on what is in the workbook rather than on the bytes of a zip.
 *
 * A class rather than the Pest helpers the rest of the suite uses: three test
 * files need this, and a global function may only be declared once.
 */
final class TransferSheet
{
    /** Idempotency Key — the column every row is matched on. */
    public const string KEY_COLUMN = 'A';

    /** Transfer Reference Number — the box the bank's reference goes in. */
    public const string REFERENCE_COLUMN = 'G';

    /**
     * The worksheet behind a rendered sheet, cell types and number formats
     * included. It holds its workbook alive, so the caller may keep only this.
     */
    public static function open(string $xlsx): Worksheet
    {
        $path = self::spill($xlsx);

        try {
            return IOFactory::createReader(IOFactory::READER_XLSX)->load($path)->getActiveSheet();
        } finally {
            unlink($path);
        }
    }

    /**
     * Every cell as a plain grid. This is what two renderings of the same
     * batch have to agree on: an xlsx is a zip, and a zip carries timestamps
     * of its own, so the bytes are free to differ where the content is not.
     *
     * @return array<int, array<int, mixed>>
     */
    public static function cells(string $xlsx): array
    {
        return self::open($xlsx)->toArray(null, false, false, false);
    }

    /**
     * The row a given idempotency key sits on, so an assertion can name the
     * payee it means instead of depending on the order items were built in.
     */
    public static function rowOf(Worksheet $sheet, string $key): int
    {
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            if ((string) $sheet->getCell(self::KEY_COLUMN.$row)->getValue() === $key) {
                return $row;
            }
        }

        throw new RuntimeException(sprintf('Idempotency key %s is not on this transfer sheet.', $key));
    }

    /**
     * The sheet as finance sends it back: the exported file with a reference
     * typed into the rows named and every other row left exactly as it came
     * out. Editing the real export rather than hand-writing a table is what
     * makes an import test a statement about the exporter's own output.
     *
     * @param  array<string, string>  $references  idempotency key → bank reference
     */
    public static function filled(string $xlsx, array $references): UploadedFile
    {
        $sheet = self::open($xlsx);

        foreach ($references as $key => $reference) {
            $sheet->setCellValueExplicit(
                self::REFERENCE_COLUMN.self::rowOf($sheet, (string) $key),
                $reference,
                DataType::TYPE_STRING,
            );
        }

        $path = self::spill('');

        try {
            (new Xlsx($sheet->getParentOrThrow()))->save($path);

            return UploadedFile::fake()->createWithContent('transfers.xlsx', (string) file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    /**
     * A temporary file holding the given bytes. PhpSpreadsheet reads and
     * writes paths, never strings, and the export travels as a response body.
     */
    private static function spill(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'manfaa-transfer-sheet');
        file_put_contents($path, $contents);

        return $path;
    }
}
