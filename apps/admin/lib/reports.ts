import { REPORT_MAX_DAYS } from '@manfaa/api-client';

/**
 * Period arithmetic for the superadmin reports, done on "YYYY-MM-DD" STRINGS
 * and integers — never on a `new Date()` read in the browser's zone.
 *
 * The API's `from`/`to` are business-timezone dates (Indian/Maldives, UTC+5),
 * and from 19:00 UTC the Maldives is already on the next day. An admin in
 * London opening this screen at 20:00 would otherwise be offered a "this
 * month" that ends yesterday, and on the 1st of a month would be offered the
 * whole of the wrong month. So the calendar the presets are computed against
 * is `businessToday()` — the Maldivian date — and every month step here is
 * integer arithmetic over that, with `Date.UTC` used only where a day count
 * is needed (it is timezone-free by construction).
 */

/** The window both report endpoints take, in business-timezone dates. */
export interface Period {
  from: string;
  to: string;
}

interface Ymd {
  year: number;
  month: number;
  day: number;
}

const YMD_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Days in a month, via UTC only — day 0 of the next month is this month's last. */
export function lastDayOfMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

/** "YYYY-MM-DD" -> parts, or null when it is not a real calendar date. */
function parseYmd(value: string): Ymd | null {
  const match = YMD_PATTERN.exec(value);
  if (match === null) {
    return null;
  }

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);

  if (month < 1 || month > 12) {
    return null;
  }
  if (day < 1 || day > lastDayOfMonth(year, month)) {
    return null;
  }

  return { year, month, day };
}

function toYmd(year: number, month: number, day: number): string {
  const pad = (value: number) => String(value).padStart(2, '0');
  return `${String(year).padStart(4, '0')}-${pad(month)}-${pad(day)}`;
}

/** Steps whole months, carrying the year. `delta` may be negative. */
function shiftMonth(
  year: number,
  month: number,
  delta: number,
): { year: number; month: number } {
  const zeroBased = year * 12 + (month - 1) + delta;
  return {
    year: Math.floor(zeroBased / 12),
    month: (((zeroBased % 12) + 12) % 12) + 1,
  };
}

/** True when the string is a real "YYYY-MM-DD" calendar date. */
export function isBusinessDate(value: string): boolean {
  return parseYmd(value) !== null;
}

/**
 * Whole days a period covers, both ends inclusive — 1 for a single day, null
 * when either end is not a date. Both instants are built with `Date.UTC`, so
 * the subtraction never crosses a DST or offset rule.
 */
export function daysInPeriod(from: string, to: string): number | null {
  const start = parseYmd(from);
  const end = parseYmd(to);

  if (start === null || end === null) {
    return null;
  }

  const startMs = Date.UTC(start.year, start.month - 1, start.day);
  const endMs = Date.UTC(end.year, end.month - 1, end.day);

  return Math.round((endMs - startMs) / 86_400_000) + 1;
}

/** The named windows, plus the hand-picked one. */
export const PERIOD_PRESETS = [
  'this_month',
  'last_month',
  'last_3_months',
  'custom',
] as const;

export type PeriodPreset = (typeof PERIOD_PRESETS)[number];

export const PERIOD_PRESET_LABELS: Record<PeriodPreset, string> = {
  this_month: 'This month',
  last_month: 'Last month',
  last_3_months: 'Last 3 months',
  custom: 'Custom',
};

/**
 * Resolves a named preset against the business calendar.
 *
 * "This month" and "Last 3 months" end TODAY rather than at month end: a
 * report is read to find out what has happened, and naming days that have
 * not happened yet only invites the reader to wonder why they are empty.
 * "Last month" is the complete previous month, both ends fixed.
 *
 * `today` is a business date from `businessToday()`; an unparseable one
 * degrades to a single-day period rather than throwing on a render.
 */
export function presetPeriod(
  preset: Exclude<PeriodPreset, 'custom'>,
  today: string,
): Period {
  const now = parseYmd(today);

  if (now === null) {
    return { from: today, to: today };
  }

  if (preset === 'last_month') {
    const previous = shiftMonth(now.year, now.month, -1);
    return {
      from: toYmd(previous.year, previous.month, 1),
      to: toYmd(
        previous.year,
        previous.month,
        lastDayOfMonth(previous.year, previous.month),
      ),
    };
  }

  const start =
    preset === 'last_3_months' ? shiftMonth(now.year, now.month, -2) : now;

  return { from: toYmd(start.year, start.month, 1), to: today };
}

/**
 * Why this period cannot be sent, or null when it can — the same three rules
 * the API validates (`date_format:Y-m-d`, `after_or_equal:from`, and the
 * ReportPeriod::MAX_DAYS span), checked here so a mistyped range says what is
 * wrong instead of costing a round trip to be told.
 */
export function periodProblem(period: Period): string | null {
  if (!isBusinessDate(period.from)) {
    return 'Pick a start date.';
  }
  if (!isBusinessDate(period.to)) {
    return 'Pick an end date.';
  }

  const days = daysInPeriod(period.from, period.to);

  if (days === null || days < 1) {
    return 'The end date falls before the start date.';
  }
  if (days > REPORT_MAX_DAYS) {
    return `A report covers at most ${REPORT_MAX_DAYS} days; this period covers ${formatCount(days)}.`;
  }

  return null;
}

/** Grouped integer, for counts and row totals ("1,204 rows"). */
export function formatCount(value: number): string {
  return new Intl.NumberFormat('en-US').format(value);
}
