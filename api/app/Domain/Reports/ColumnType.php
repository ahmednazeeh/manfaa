<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * What a column's cells MEAN, which is what tells the writer how to render
 * them and the panel how to align and format them.
 *
 * The wire representation of a value is fixed by its type and never varies:
 *
 *   text    string|null
 *   int     int|null
 *   money   int|null   — LAARI, always (the writer divides by 100)
 *   percent int|null   — BASIS POINTS, always (the writer divides by 10,000)
 *   date    CarbonImmutable|null in rows; ISO-8601 string in JSON
 *
 * Money and percent stay integers all the way to the cell for the §4
 * reason: no float ever touches money before the last possible moment.
 */
enum ColumnType: string
{
    case Text = 'text';
    case Int = 'int';
    case Money = 'money';
    case Percent = 'percent';
    case Date = 'date';

    /** Columns a totals row can legitimately SUM. */
    public function isSummable(): bool
    {
        return $this === self::Money || $this === self::Int;
    }

    /** A sensible starting width, in characters, before the label is considered. */
    public function width(): int
    {
        return match ($this) {
            self::Text => 18,
            self::Int => 10,
            self::Money => 14,
            self::Percent => 10,
            self::Date => 18,
        };
    }
}
