<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use RuntimeException;

/**
 * The row cap (owner, 2026-08-24): an interactive export builds the whole
 * workbook in memory, so a period nobody meant to ask for is refused with a
 * sentence that says what to do about it rather than timing out behind a
 * spinner. Queued exports are deferred; when they arrive this is the
 * exception that stops meaning "no" and starts meaning "not here".
 */
final class ReportTooLargeException extends RuntimeException
{
    public const string CODE = 'report_too_large';

    /** Rows above which the on-screen report refuses (owner, 2026-08-24). */
    public const int MAX_ROWS = 50000;

    /**
     * Rows above which the .XLSX refuses — a measured number, not a round
     * one, because the workbook is the half that cannot hold 50,000.
     *
     * A Sheet is plain arrays and 50,000 rows of one cost ~14 MB over
     * baseline, so the preview keeps the owner's cap unchanged. PhpSpreadsheet
     * holds every cell as an object: measured through XlsxWriter on the
     * 22-column cashback Transactions sheet, 0 rows peaked at 44.0 MB, 2,000
     * at 81.0 MB and 8,000 at 177.4 MB — ~16.5 MB per thousand rows, linear,
     * against `php_admin_value[memory_limit] = 256M` in the manfaa pool.
     * 50,000 rows would need roughly 825 MB on top of baseline; at 14,000 it
     * dies for real ("Allowed memory size of 268435456 bytes exhausted" in
     * zipstream's File.php), which is a 500 with no JSON body, no
     * `report_too_large` for the panel to catch, and a temp workbook full of
     * customer data left behind.
     *
     * 8,000 is the largest measured point that leaves headroom at 256M, and
     * it is also where the second ceiling starts to bite: the spreadsheet
     * write alone took 9.0s against `max_execution_time = 180`.
     *
     * This is the number to raise when queued or streaming exports land —
     * not MAX_ROWS, which is already honest about what a screen can show.
     */
    public const int MAX_EXPORT_ROWS = 8000;

    public function __construct(public readonly int $rowCount, public readonly int $limit = self::MAX_ROWS)
    {
        parent::__construct(sprintf(
            'This period covers %s rows, over the %s the export can build at once — narrow the period.',
            number_format($rowCount),
            number_format($limit),
        ));
    }

    public static function ifOver(int $rowCount, int $limit = self::MAX_ROWS): void
    {
        if ($rowCount > $limit) {
            throw new self($rowCount, $limit);
        }
    }
}
