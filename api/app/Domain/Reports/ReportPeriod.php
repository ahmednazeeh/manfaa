<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting window, named in BUSINESS-timezone dates and used as a
 * half-open instant range [start, end).
 *
 * Half-open on purpose. A closed range needs an "end of day" instant, and
 * the honest one — 23:59:59.999999 — is a fiction that silently drops a
 * transaction stamped in the last microsecond of the day. `< end` where end
 * is midnight at the START of the day after `to` has no such gap and no
 * such fudge, and it is the same rule everywhere in the three reports, so
 * no two sheets can ever disagree about which side of midnight a row sits.
 *
 * The dates are the admin's own: 2026-08-01 means the Maldivian first of
 * August, not the UTC one. A transaction at 02:00 Malé on the 1st happened
 * at 21:00 UTC on 31 July, and it belongs to August — which is exactly the
 * boundary the cashback report is tested at.
 */
final readonly class ReportPeriod
{
    /** The longest span an interactive (non-queued) export may cover. */
    public const int MAX_DAYS = 366;

    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $timezone,
    ) {}

    /**
     * @param  string  $from  Y-m-d in business time, inclusive
     * @param  string  $to  Y-m-d in business time, inclusive
     */
    public static function of(string $from, string $to, ?string $timezone = null): self
    {
        $timezone ??= self::businessTimezone();

        $fromDate = CarbonImmutable::createFromFormat('Y-m-d', $from, $timezone);
        $toDate = CarbonImmutable::createFromFormat('Y-m-d', $to, $timezone);

        if ($fromDate === false || $toDate === false) {
            throw new InvalidArgumentException('A report period needs two Y-m-d dates.');
        }

        $fromDate = $fromDate->startOfDay();
        $toDate = $toDate->startOfDay();

        if ($toDate->lessThan($fromDate)) {
            throw new InvalidArgumentException('A report period cannot end before it starts.');
        }

        return new self(
            from: $fromDate,
            to: $toDate,
            // Stored in UTC: a query binding carrying a +05:00 instant is
            // formatted without its offset by some drivers and read back
            // five hours from where it belongs (the same trap
            // PayoutBatchBuilder documents).
            start: $fromDate->utc(),
            end: $toDate->addDay()->utc(),
            timezone: $timezone,
        );
    }

    /** Whole days covered, inclusive of both ends. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->format('Y-m-d');
    }

    /**
     * The window as the API states it back to the caller.
     *
     * @return array{from: string, to: string, timezone: string, days: int}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'timezone' => $this->timezone,
            'days' => $this->days(),
        ];
    }

    public static function businessTimezone(): string
    {
        return (string) config('app.business_timezone', 'Indian/Maldives');
    }
}
