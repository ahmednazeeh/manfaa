<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * "Print this label once per group" — a WORKBOOK PRESENTATION rule, and
 * nothing else (owner, 2026-08-24).
 *
 * One payout batch pays many customers, so its reference used to repeat
 * down a whole column and a batch read as forty unrelated rows. Printed
 * once, at the top of its block, it reads as what it is: one batch.
 *
 * THE SPLIT THIS CLASS EXISTS TO KEEP. The rows themselves stay FULLY
 * POPULATED — Sheet::$rows is data, and the JSON preview serialises it
 * unchanged, because a preview is a table a machine reads, not a printed
 * page. Only XlsxWriter, at the moment it writes a cell, leaves the
 * repeated label out. Blanking the stored row instead would put a hole in
 * the API response, and every consumer of it would have to learn to
 * back-fill downwards.
 *
 * TWO PROPERTIES THE BLANKING MUST KEEP, both of them about Excel:
 *
 *  - A blanked cell is a REAL BLANK — no space, no empty string. A space is
 *    a value: it sorts, it filters, it breaks =COUNTBLANK, and it makes the
 *    column look empty while being full.
 *  - The sheet still carries a MACHINE-READABLE KEY for the group, as an
 *    ordinary VISIBLE column ($keyColumn). An autofilter ignores nothing —
 *    it reads exactly the cells that are there — so filtering on the
 *    blanked label column would find only the first row of each batch.
 *    Filtering on the key column finds every row of it. A hidden column
 *    would not do: hiding is a view setting, and the reader who needs the
 *    key is the reader who cannot see it.
 *
 * $keyColumn also decides where one group ENDS: rows are compared on the
 * key, never on the printed label, because two different batches can
 * legitimately carry the same reference (a cancelled batch keeps its
 * reference — production holds three PB-20260816 rows) and collapsing them
 * into one block would state something false about the money.
 */
final readonly class SheetGrouping
{
    public function __construct(
        /** The machine key that identifies a group; printed on EVERY row. */
        public string $keyColumn,
        /** The human label printed only on the FIRST row of each group. */
        public string $labelColumn,
    ) {}
}
