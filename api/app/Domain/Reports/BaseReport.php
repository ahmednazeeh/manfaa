<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Customers\MaskedName;
use Carbon\CarbonImmutable;

/**
 * What the three reports share: the window, the optional merchant filter,
 * one build pass that every reader (preview, summary, workbook) is served
 * from, and the single place a stored timestamp becomes a business-time
 * instant.
 *
 * Building once matters. The JSON preview quotes the summary and the first
 * fifty rows; the export writes the same figures into a workbook. If those
 * came from two passes they could disagree across a midnight, or across a
 * settlement matched while an admin was reading — so they come from one.
 */
abstract class BaseReport implements Report
{
    /** @var list<Sheet>|null */
    private ?array $sheets = null;

    private ?int $rowCount = null;

    public function __construct(
        public readonly ReportPeriod $period,
        public readonly ?int $merchantId = null,
    ) {}

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
     * The masking idiom every customer name in every report goes through.
     */
    protected function maskedName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : MaskedName::of($name);
    }
}
