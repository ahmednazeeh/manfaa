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
     * The SAME report — same window, same merchant, same reversed-rows
     * choice — rendered for the .xlsx: full customer names, full bank
     * account numbers, full account names.
     *
     * A new instance, never a mutation. Masking is fixed at construction so
     * that "this object is the unmasked one" is a property of the object
     * rather than of the order calls happened to be made in, and so the
     * preview's guard has something it can actually check.
     */
    public function forExport(): static;

    /** Whether reversed transaction rows are in this render. */
    public function includeReversed(): bool;

    /**
     * Whether `include_reversed` can change what this report contains at
     * all.
     *
     * False on the payout report (every row was paid, and paid is terminal)
     * and on the earnings report (built from the ledger, which always keeps
     * reversal journals). For those two the setting is inert, so the
     * workbook says so instead of describing rows it cannot hold, the
     * filename does not advertise a difference between two identical files,
     * and the audit row does not record a render choice that never happened.
     */
    public function reversedRowsApply(): bool;

    /**
     * The prose block the Summary sheet opens with — period, filter,
     * reversed-rows setting and the money-direction glossary — so the panel
     * can show the reader exactly what the workbook will say.
     */
    public function headerBlock(): ?HeaderBlock;

    /**
     * The JSON preview's body: the primary sheet's title, its column meta
     * and its first $limit rows.
     *
     * The only route from a report to a JSON body, and it REFUSES an
     * unmasked render. See BaseReport::previewPayload().
     *
     * @return array{sheet: string, columns: list<array{key: string, label: string, type: string}>, rows: list<list<int|string|null>>}
     */
    public function previewPayload(int $limit): array;

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
