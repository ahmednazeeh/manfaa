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

const monthFormat = new Intl.DateTimeFormat('en-GB', {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
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

/** Date-only string ("2026-07-01") -> "July 2026". */
export function formatMonth(dateOnly: string): string {
  return monthFormat.format(new Date(`${dateOnly}T00:00:00Z`));
}
