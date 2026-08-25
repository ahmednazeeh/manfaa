/**
 * Display formatting for timestamps. Storage is UTC; business rules are
 * evaluated in UTC+5 — everything the admin reads is therefore shown in
 * Indian/Maldives so due dates line up with the §7 clock.
 */

const TIME_ZONE = 'Indian/Maldives';

const dateTimeFormat = new Intl.DateTimeFormat('en-GB', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  hour12: false,
  timeZone: TIME_ZONE,
});

const dateFormat = new Intl.DateTimeFormat('en-GB', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
  timeZone: TIME_ZONE,
});

const businessDateFormat = new Intl.DateTimeFormat('en-GB', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  timeZone: TIME_ZONE,
});

/** ISO timestamp -> "14 Aug 2026, 16:05" in Maldives time. */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }
  return dateTimeFormat.format(new Date(iso));
}

/** ISO timestamp or date -> "14 Aug 2026" in Maldives time. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }
  return dateFormat.format(new Date(iso));
}

/**
 * Today's date as the business day sees it, "YYYY-MM-DD" — the form a payout
 * cutoff travels in.
 *
 * From 19:00 UTC the Maldives is already on the next day, so the UTC date
 * names yesterday through those five hours. An admin building a batch then
 * would cut it off a day early and strand a day of confirmed rewards until
 * the next run, and the same wrong date as the input's `max` would forbid
 * correcting it.
 */
export function businessToday(): string {
  const parts = businessDateFormat.formatToParts(new Date());
  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((candidate) => candidate.type === type)?.value ?? '';

  return `${part('year')}-${part('month')}-${part('day')}`;
}

/**
 * The Maldives is UTC+5 and has no DST, so a wall-clock instant there is a
 * fixed offset from UTC and needs no timezone database to build.
 */
export const MALDIVES_OFFSET_MS = 5 * 60 * 60 * 1000;

/**
 * An instant as a `<input type="datetime-local">` value in MALDIVES wall
 * clock — "YYYY-MM-DDTHH:mm".
 *
 * The input itself is timezone-blind: it shows whatever text it is given and
 * hands the same text back. Feeding it a browser-local rendering would let an
 * admin in London schedule a promotion five hours away from where they read
 * it, so every screen that edits an instant states it in Malé time and says
 * so on the label.
 */
export function toMaldivesLocalInput(date: Date): string {
  return new Date(date.getTime() + MALDIVES_OFFSET_MS)
    .toISOString()
    .slice(0, 16);
}

/**
 * The reverse: a datetime-local value read as MALDIVES wall clock, returned
 * as an ISO string carrying the offset explicitly (`…:00+05:00`).
 *
 * Null for anything that is not a complete "YYYY-MM-DDTHH:mm" — which is what
 * a native date input emits for every half-typed edit — so a caller blocks on
 * the incomplete value instead of sending a malformed instant.
 */
export function toIsoWithMaldivesOffset(local: string): string | null {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) {
    return null;
  }
  const iso = `${local}:00+05:00`;
  return Number.isNaN(new Date(iso).getTime()) ? null : iso;
}

/**
 * Midnight in Malé of the day an instant falls on, as epoch milliseconds.
 * Whole-day arithmetic (a promotion's window, the days left in one) is done
 * against this so a merchant counting on their own calendar agrees with the
 * server, which does exactly this in the business timezone.
 */
export function startOfMaldivesDayMs(ms: number): number {
  const shifted = ms + MALDIVES_OFFSET_MS;
  return Math.floor(shifted / 86_400_000) * 86_400_000 - MALDIVES_OFFSET_MS;
}
