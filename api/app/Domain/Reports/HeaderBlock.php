<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * The block a Summary sheet opens with, above its column header (owner,
 * 2026-08-24): what this report is, what window it covers, what filter was
 * applied, whether reversed rows are in it — and a short glossary naming the
 * two directions money moves.
 *
 * WHY IT EXISTS. Manfaa has two opposite flows and one vocabulary that
 * blurs them:
 *
 *     MERCHANT SETTLEMENT   money IN, from the merchant to Manfaa
 *     CUSTOMER PAYOUT       money OUT, from Manfaa to the customer
 *
 * A tax professional handed a sheet headed "Settlements" cannot tell from
 * the word alone which of those it is — "settling" is something either side
 * of a debt does. Sheet titles now carry the direction and so do the
 * ambiguous column labels, but a workbook that has to be read months later
 * by somebody who was never in the room needs the sentence written down
 * once, in full, where they will hit it first.
 *
 * IT IS NOT DATA. The block is rendered by XlsxWriter into the worksheet
 * only; it is never pushed into Sheet::$rows, so it cannot reach
 * previewRows(), cannot be summed by a totals formula, and cannot shift a
 * positional row's column meaning. The JSON preview carries it as its own
 * object instead (Report::headerBlock()), which is what lets the panel show
 * the reader the same sentences the file will carry.
 */
final readonly class HeaderBlock
{
    /**
     * @param  string  $title  the report's own name, first line of the sheet
     * @param  list<array{label: string, value: string}>  $facts  label in column A, value in column B
     * @param  list<string>  $notes  the glossary, one sentence per row, column A
     */
    public function __construct(
        public string $title,
        public array $facts = [],
        public array $notes = [],
    ) {}

    /**
     * The block as rows of two cells, exactly as the writer lays them out —
     * column A and column B, blank strings for the spacers.
     *
     * height() counts THIS, rather than recomputing the arithmetic from the
     * fact and note counts. The frozen pane, the autofilter range and every
     * SUM formula are offset by that number, so a layout change that
     * forgot to update a separately-derived height would silently point the
     * totals at the wrong cells — the one failure mode this whole block is
     * most likely to cause.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function lines(): array
    {
        $lines = [[$this->title, '']];

        foreach ($this->facts as $fact) {
            $lines[] = [$fact['label'], $fact['value']];
        }

        if ($this->notes !== []) {
            $lines[] = ['', ''];

            foreach ($this->notes as $note) {
                $lines[] = [$note, ''];
            }
        }

        // One blank row between the block and the column header, so the
        // header still reads as a header and the autofilter's dropdown
        // arrows do not sit against the prose.
        $lines[] = ['', ''];

        return $lines;
    }

    /** Worksheet rows the block occupies — the offset everything below it takes. */
    public function height(): int
    {
        return count($this->lines());
    }

    /** How many of those rows are label/value facts, for the writer's bolding. */
    public function factCount(): int
    {
        return count($this->facts);
    }

    /**
     * @return array{title: string, facts: list<array{label: string, value: string}>, notes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'facts' => $this->facts,
            'notes' => $this->notes,
        ];
    }
}
