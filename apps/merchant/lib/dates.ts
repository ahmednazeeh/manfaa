/**
 * Dates that come from a RULE rather than from an event.
 *
 * §13: every age rule in this product is evaluated in the BUSINESS timezone
 * (Indian/Maldives, UTC+5, no DST). The API answers in instants, and a
 * timestamp printed in the browser's own zone is fine for "you created this
 * at" — but not for the day a rule turns over. "Your oldest sale stops
 * earning the prompt-payment discount on the 15th" is a boundary the server
 * computes in UTC+5; printed in UTC it reads a day early, and the merchant
 * settles a day late for nothing.
 *
 * The only arithmetic here is adding whole days to an instant, which is exact
 * in a zone with no DST. It is not money, and it is never a decision: whether
 * a line still qualifies is the server's answer (PLAN §1, re-checked at
 * submit) — this module only says WHICH DAY that answer changes.
 */

export const BUSINESS_TIMEZONE = 'Indian/Maldives';

const MS_PER_DAY = 86_400_000;

/** An instant, plus whole days. */
export function addDaysToInstant(iso: string, days: number): Date {
  return new Date(new Date(iso).getTime() + days * MS_PER_DAY);
}

/**
 * "15 Aug 2026" in the business timezone. Latin digits in both languages —
 * the MVR convention the rest of the panel follows (see lib/i18n.ts).
 */
export function formatBusinessDate(value: Date): string {
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: BUSINESS_TIMEZONE,
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(value);
}
