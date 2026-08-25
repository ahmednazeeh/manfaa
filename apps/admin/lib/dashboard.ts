import { type DashboardWindow } from '@manfaa/api-client';
import { presetPeriod, type Period } from '@/lib/reports';

/**
 * The dashboard's own window arithmetic and number formatting.
 *
 * Every date here is a BUSINESS date — "YYYY-MM-DD" as the Maldives has it —
 * and every step over one is integer arithmetic on the string, exactly as
 * `lib/reports.ts` does it. `new Date(value)` never appears: from 19:00 UTC
 * the Maldives is already on tomorrow, so a browser-local Date would offer an
 * admin in London a "this month" that ends yesterday, and would slide a chart
 * bar a whole day westward.
 *
 * The two presets the reports screen already owns are reused from it rather
 * than restated; only "Last 30 days", which the reports screen has no need
 * for, is computed here.
 */

const YMD_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

const MONTHS = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

/** The four windows the whole page is read through. */
export const DASHBOARD_PRESETS = [
  'this_month',
  'last_month',
  'last_30_days',
  'custom',
] as const;

export type DashboardPreset = (typeof DASHBOARD_PRESETS)[number];

export const DASHBOARD_PRESET_LABELS: Record<DashboardPreset, string> = {
  this_month: 'This month',
  last_month: 'Last month',
  last_30_days: 'Last 30 days',
  custom: 'Custom',
};

/**
 * Steps a business date by whole days. `Date.UTC` is used only as day-count
 * arithmetic — it is timezone-free by construction, and the result is read
 * back through the UTC getters, so no local offset can leak in.
 */
export function shiftBusinessDays(date: string, delta: number): string {
  const match = YMD_PATTERN.exec(date);

  if (match === null) {
    return date;
  }

  const shifted = new Date(
    Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) +
      delta * 86_400_000,
  );
  const pad = (value: number) => String(value).padStart(2, '0');

  return `${shifted.getUTCFullYear()}-${pad(shifted.getUTCMonth() + 1)}-${pad(
    shifted.getUTCDate(),
  )}`;
}

/**
 * Resolves a named preset against the business calendar.
 *
 * "Last 30 days" is thirty days INCLUDING today — today minus 29 — because a
 * window that ends yesterday is a window whose newest figure is always stale
 * by a day, and the reader will not know it.
 */
export function dashboardPresetPeriod(
  preset: Exclude<DashboardPreset, 'custom'>,
  today: string,
): Period {
  if (preset === 'last_30_days') {
    return { from: shiftBusinessDays(today, -29), to: today };
  }

  return presetPeriod(preset, today);
}

/**
 * ONE CACHE ENTRY FOR EVERY ATTENTION COUNT ON THE SCREEN.
 *
 * The nav badges and the dashboard's attention tiles are the same six
 * numbers, so they share this key: the badges subscribe to it, and the
 * landing page seeds it with the `attention` block its own request already
 * carried. A badge and the tile it links to are then literally one read at
 * one instant — never two timers landing seconds apart on a queue somebody
 * is working.
 *
 * It is deliberately NOT period-scoped. The dashboard's key carries the
 * window (`['admin','dashboard',from,to]`) because its money and chart do;
 * none of the six queues is periodised, so a badge has no window.
 */
export const ADMIN_ATTENTION_QUERY_KEY = ['admin', 'attention'] as const;

/** The window in the shape `getAdminDashboard` takes — both dates or neither. */
export function dashboardWindow(period: Period): DashboardWindow {
  return { from: period.from, to: period.to };
}

/**
 * A business date as a short day label — "4 Aug", or "4 Aug 2026" long.
 *
 * Formatted from the STRING's own parts. Passing a "YYYY-MM-DD" to `new
 * Date()` parses it as UTC midnight, which in a browser west of Greenwich
 * renders as the previous day — one silently mislabelled bar on every chart.
 */
export function formatBusinessDay(
  date: string,
  style: 'short' | 'long' = 'short',
): string {
  const match = YMD_PATTERN.exec(date);

  if (match === null) {
    return date;
  }

  const day = Number(match[3]);
  const month = MONTHS[Number(match[2]) - 1] ?? '';

  return style === 'long' ? `${day} ${month} ${match[1]}` : `${day} ${month}`;
}

/**
 * Integer laari as a compact rufiyaa string for axis ticks — "12.4K", "1.2M".
 * Ticks are read at a glance and stacked vertically, so the full grouped
 * amount belongs in the tooltip and the table, not on the axis.
 */
export function compactMvr(laari: number): string {
  const rufiyaa = laari / 100;
  const magnitude = Math.abs(rufiyaa);

  const trim = (value: number) =>
    String(
      Math.abs(value) >= 100 ? Math.round(value) : Math.round(value * 10) / 10,
    );

  if (magnitude >= 1_000_000) {
    return `${trim(rufiyaa / 1_000_000)}M`;
  }
  if (magnitude >= 1_000) {
    return `${trim(rufiyaa / 1_000)}K`;
  }

  return String(Math.round(rufiyaa));
}

/**
 * How this period's figure moved against the preceding one.
 *
 * `first` is the case a naive percentage gets wrong: dividing by a previous
 * period of zero is either a crash or "+Infinity%", and a finance tile that
 * prints "+∞%" is stating a number nobody owns. It is reported as what it
 * actually is — there was nothing before this — and no percentage is shown.
 */
export type Movement =
  /** Nothing in either window. */
  | { kind: 'idle' }
  /** Nothing in the previous window, something in this one. */
  | { kind: 'first' }
  /** Moved by less than the smallest figure worth printing. */
  | { kind: 'level' }
  | { kind: 'change'; percent: number; up: boolean };

export function movement(current: number, previous: number): Movement {
  if (previous === 0) {
    return current === 0 ? { kind: 'idle' } : { kind: 'first' };
  }

  const percent = ((current - previous) / Math.abs(previous)) * 100;

  // Below a tenth of a percent nothing readable would print, and an arrow
  // beside "0.0%" reads as a direction that is not there.
  if (Math.abs(percent) < 0.05) {
    return { kind: 'level' };
  }

  return { kind: 'change', percent, up: percent > 0 };
}

/**
 * A movement as words. Percentages are capped at 999%: past that the figure
 * is a multiple, not a rate, and four digits of precision on "this thing was
 * near zero last month" is false confidence.
 */
export function formatMovement(value: Movement): string {
  switch (value.kind) {
    case 'idle':
      return 'nothing either period';
    case 'first':
      return 'no prior activity';
    case 'level':
      return 'level';
    default: {
      const sign = value.up ? '+' : '-';
      const magnitude = Math.abs(value.percent);

      if (magnitude >= 1000) {
        return `${sign}999%+`;
      }

      return `${sign}${
        magnitude >= 100 ? Math.round(magnitude) : magnitude.toFixed(1)
      }%`;
    }
  }
}
