<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Customers\MaskedName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What the three reports share: the window, the optional merchant filter,
 * the render options (reversed rows in or out, masked or full), one build
 * pass that every reader (preview, summary, workbook) is served from, and
 * the single place a stored timestamp becomes a business-time instant.
 *
 * Building once matters. The JSON preview quotes the summary and the first
 * fifty rows; the export writes the same figures into a workbook. If those
 * came from two passes they could disagree across a midnight, or across a
 * settlement matched while an admin was reading — so they come from one.
 *
 * Building once is also why MASKING IS FIXED AT CONSTRUCTION. The sheets
 * are memoised, so a masking flag that could be flipped after a build would
 * hand the caller whichever variant happened to be cached — the preview
 * showing full account numbers because an export ran first, or the export
 * shipping "Ais*** Moh***" to MIRA because a preview did. forExport()
 * returns a NEW report with its own empty cache, and previewPayload()
 * refuses to serialise an unmasked one at all.
 */
abstract class BaseReport implements Report
{
    /** @var list<Sheet>|null */
    private ?array $sheets = null;

    private ?int $rowCount = null;

    public readonly ReportOptions $options;

    public function __construct(
        public readonly ReportPeriod $period,
        public readonly ?int $merchantId = null,
        ?ReportOptions $options = null,
    ) {
        // A caller that says nothing gets reversals excluded and names
        // masked — the safe render, in both directions.
        $this->options = $options ?? ReportOptions::default();
    }

    public function forExport(): static
    {
        return new static($this->period, $this->merchantId, $this->options->unmasked());
    }

    public function includeReversed(): bool
    {
        return $this->options->includeReversed;
    }

    /**
     * @return list<Sheet>
     */
    public function sheets(): array
    {
        return $this->sheets ??= $this->build();
    }

    public function rowCount(): int
    {
        return $this->rowCount ??= $this->countRows();
    }

    public function sheet(string $title): Sheet
    {
        foreach ($this->sheets() as $sheet) {
            if ($sheet->title === $title) {
                return $sheet;
            }
        }

        throw new \InvalidArgumentException(sprintf('Report [%s] has no sheet [%s].', $this->key(), $title));
    }

    public function primarySheet(): Sheet
    {
        return $this->sheet($this->primarySheetTitle());
    }

    public function headerBlock(): ?HeaderBlock
    {
        foreach ($this->sheets() as $sheet) {
            if ($sheet->header !== null) {
                return $sheet->header;
            }
        }

        return null;
    }

    /**
     * The preview's body — and the wall between the two renders.
     *
     * Every JSON path out of a report goes through here, so the check is in
     * one place and cannot be forgotten by the next controller. It throws
     * rather than quietly re-masking: a Full report reaching a JSON
     * serialiser means a caller believes something false about which
     * artefact it is holding, and silently fixing the output would leave
     * that belief in place to cause the next bug.
     *
     * @return array{sheet: string, columns: list<array{key: string, label: string, type: string}>, rows: list<list<int|string|null>>}
     */
    public function previewPayload(int $limit): array
    {
        if (! $this->options->isMasked()) {
            throw new LogicException(sprintf(
                'Report [%s] was built for EXPORT — it carries full customer names and full bank account '
                .'numbers, and must never be serialised into a JSON preview. Build a masked report for the '
                .'screen and call forExport() only for the workbook.',
                $this->key(),
            ));
        }

        $sheet = $this->primarySheet();

        return [
            'sheet' => $sheet->title,
            'columns' => $sheet->columnMeta(),
            'rows' => $sheet->previewRows($limit),
        ];
    }

    /**
     * @return list<Sheet>
     */
    abstract protected function build(): array;

    abstract protected function countRows(): int;

    /**
     * A stored timestamp as a BUSINESS-time instant, which is what every
     * date cell in every sheet carries: the workbook writes the object's own
     * clock fields, and the JSON preview serialises them with their +05:00
     * offset, so both say the hour the Maldives saw.
     */
    protected function at(mixed $timestamp): ?CarbonImmutable
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        if ($timestamp instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($timestamp)->setTimezone($this->period->timezone);
        }

        return CarbonImmutable::parse((string) $timestamp)->setTimezone($this->period->timezone);
    }

    /**
     * A person's name as THIS render should carry it: masked on screen,
     * whole in the workbook.
     *
     * Every customer name and every bank account name in every report goes
     * through here. MaskedName itself is left alone — the hold queue, the
     * V1 lookup and the referral list still call it directly, and they are
     * always masked.
     */
    protected function personName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        return $this->options->isMasked() ? MaskedName::of($name) : $name;
    }

    /**
     * A bank account number as THIS render should carry it: last four on
     * screen, the whole number in the workbook.
     *
     * The export is the artefact somebody reconciles against a bank
     * statement line by line; "****4821" cannot be matched against
     * anything. ReportLabels::maskedAccount stays exactly as it was — it is
     * the masking rule, and this is the choice of whether to apply it.
     */
    protected function bankAccount(?string $account): string
    {
        $account = trim((string) $account);

        if ($account === '') {
            return '';
        }

        return $this->options->isMasked() ? ReportLabels::maskedAccount($account) : $account;
    }

    /**
     * The block the Summary sheet opens with. Every report states the same
     * four facts and the same two-line glossary; $notes is what each one
     * adds about itself.
     *
     * @param  list<string>  $notes
     */
    protected function headerFor(string $title, array $notes = []): HeaderBlock
    {
        return new HeaderBlock(
            title: $title,
            facts: [
                ['label' => 'Period', 'value' => sprintf('%s to %s', $this->period->fromDate(), $this->period->toDate())],
                ['label' => 'Timezone', 'value' => $this->period->timezone],
                ['label' => 'Merchant', 'value' => $this->merchantFilterLabel()],
                ['label' => 'Reversed rows', 'value' => $this->reversedRowsLabel()],
            ],
            notes: [...self::DIRECTIONS, ...$notes],
        );
    }

    /**
     * The glossary, verbatim on every report. Manfaa's money moves two
     * opposite ways and the domain word for each ("settlement", "payout")
     * reads as the other one to somebody who does not already know which is
     * which — which is precisely the reader this block is for.
     *
     * @var list<string>
     */
    private const array DIRECTIONS = [
        'MERCHANT SETTLEMENT = money IN. What a merchant pays Manfaa: their customers\' cashback, '
            .'plus the platform fee, plus GST on that fee, less any prompt-payment discount.',
        'CUSTOMER PAYOUT = money OUT. What Manfaa pays a customer: the cashback they earned, sent to '
            .'their bank account.',
    ];

    private function merchantFilterLabel(): string
    {
        if ($this->merchantId === null) {
            return 'All merchants';
        }

        $name = DB::table('merchants')->where('id', $this->merchantId)->value('name');

        // The id stays beside the name: two shops can be renamed into the
        // same string, and a workbook filed for a year should still say
        // which row of `merchants` it was filtered to.
        return sprintf('%s (id %d)', (string) ($name ?? 'Unknown merchant'), $this->merchantId);
    }

    /**
     * Whether `include_reversed` can change what THIS report contains.
     *
     * Derived from reversedRowsNotApplicable() rather than declared beside
     * it, so the header block's sentence and the flag's effective value can
     * never disagree: a report that explains why the setting does nothing to
     * it is, by that same fact, a report the setting does nothing to. The
     * controller reads this before putting `-with-reversed` in a filename or
     * `include_reversed = true` on an audit row.
     */
    public function reversedRowsApply(): bool
    {
        return $this->reversedRowsNotApplicable() === null;
    }

    /**
     * Why the reversed-rows setting cannot change this report — or null when
     * it can, which is the cashback report and nothing else today.
     *
     * A report that overrides this says the SAME sentence in both flag
     * states, because in both flag states it holds the same rows. The
     * default label was written for the cashback report and reads as a lie
     * anywhere else: it promises reversed sales "appear below, with
     * 'reversed' in their State column" on two reports that have no State
     * column and cannot carry a reversed sale at all — printed three rows
     * above each report's own note saying exactly that. One header block
     * contradicting itself, in the audited file a tax professional reads, is
     * worse than no header block.
     */
    protected function reversedRowsNotApplicable(): ?string
    {
        return null;
    }

    private function reversedRowsLabel(): string
    {
        return $this->reversedRowsNotApplicable() ?? ($this->options->includeReversed
            ? 'INCLUDED — reversed sales appear below, with "reversed" in their State column.'
            : 'Excluded — reversed sales are not counted on this report.');
    }
}
