<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * The contract every report answers to, so the controller and the writer
 * know nothing about cashback, payouts or ledgers.
 */
interface Report
{
    /** The {report} path segment this report answers to. */
    public function key(): string;

    /**
     * How many rows the report's DRIVING query returns — counted before
     * anything is built, so an oversized period is refused rather than
     * assembled and then refused.
     */
    public function rowCount(): int;

    /**
     * Every sheet, in workbook order — Summary first, always.
     *
     * @return list<Sheet>
     */
    public function sheets(): array;

    /** The sheet the JSON preview shows, and the one rowCount() counts. */
    public function primarySheetTitle(): string;

    /** That sheet, built. */
    public function primarySheet(): Sheet;

    /**
     * The headline figures, for the panel's preview card.
     *
     * @return array<string, mixed>
     */
    public function summary(): array;
}
