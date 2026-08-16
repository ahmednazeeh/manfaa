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
