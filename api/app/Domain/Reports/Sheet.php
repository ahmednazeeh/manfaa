<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One worksheet: an ordered column list, rows as positional arrays matching
 * that list, and the keys a totals row should SUM.
 *
 * Rows are positional rather than associative because the column order IS
 * the sheet — a row that carries its own key order can disagree with the
 * header, and a finance sheet where column six is sometimes GST and
 * sometimes the fee is worse than no sheet at all. Construction goes
 * through push(), which refuses a row of the wrong width.
 *
 * A sheet may also carry a HeaderBlock — prose above the column header,
 * held apart from $rows so it can never be summed, filtered, previewed or
 * counted as data. Only the workbook renders it.
 */
final class Sheet
{
    /** @var list<list<CarbonImmutable|int|string|null>> */
    private array $rows = [];

    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<string>  $totals  column keys to SUM in the totals row
     * @param  ?HeaderBlock  $header  prose above the column header; workbook only
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $totals = [],
        public readonly ?HeaderBlock $header = null,
    ) {
        foreach ($totals as $key) {
            $column = $this->column($key);

            if ($column === null || ! $column->type->isSummable()) {
                throw new InvalidArgumentException(sprintf(
                    'Sheet [%s] cannot total column [%s]: only money and int columns are summable.',
                    $title,
                    $key,
                ));
            }
        }
    }

    /**
     * @param  list<CarbonImmutable|int|string|null>  $row
     */
    public function push(array $row): void
    {
        if (count($row) !== count($this->columns)) {
            throw new InvalidArgumentException(sprintf(
                'Sheet [%s] expects %d cells per row, got %d.',
                $this->title,
                count($this->columns),
                count($row),
            ));
        }

        $this->rows[] = array_values($row);
    }

    /**
     * @return list<list<CarbonImmutable|int|string|null>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function column(string $key): ?ReportColumn
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column;
            }
        }

        return null;
    }

    /** The zero-based position of a column key, or null when absent. */
    public function indexOf(string $key): ?int
    {
        foreach ($this->columns as $index => $column) {
            if ($column->key === $key) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The sum of one summable column — what the workbook's SUM formula will
     * evaluate to, available to a caller (and a test) without opening a
     * spreadsheet.
     */
    public function sum(string $key): int
    {
        $index = $this->indexOf($key);

        if ($index === null) {
            throw new InvalidArgumentException(sprintf('Sheet [%s] has no column [%s].', $this->title, $key));
        }

        $total = 0;

        foreach ($this->rows as $row) {
            $total += (int) ($row[$index] ?? 0);
        }

        return $total;
    }

    /**
     * Column metadata for the JSON preview.
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    public function columnMeta(): array
    {
        return array_map(static fn (ReportColumn $column): array => $column->toArray(), $this->columns);
    }

    /**
     * The first $limit rows, JSON-ready: dates become ISO-8601 strings and
     * everything else is already a scalar of the type the column declares.
     *
     * @return list<list<int|string|null>>
     */
    public function previewRows(int $limit): array
    {
        $preview = [];

        foreach (array_slice($this->rows, 0, $limit) as $row) {
            $preview[] = array_map(
                static fn ($value) => $value instanceof CarbonImmutable ? $value->toIso8601String() : $value,
                $row,
            );
        }

        return $preview;
    }
}
