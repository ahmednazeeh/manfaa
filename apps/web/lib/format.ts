import { format, parseISO } from 'date-fns';

/**
 * Display-only formatting helpers. Money arithmetic stays in integer laari
 * and rate arithmetic in integer basis points (`percentToBp`) everywhere —
 * floats appear only in non-monetary display maths (distances).
 */

/**
 * A rate as it arrives on the wire (PLAN §1: a 2-decimal percent STRING —
 * "2.00", "0.75", "12.50") rendered the way this storefront has always
 * rendered rates: trailing zeros dropped, so "2.00" reads "2%" and "5.50"
 * reads "5.5%".
 *
 * The server's digits are passed through verbatim — trimmed, never parsed
 * and re-formatted — so no number exists in the display path at all and a
 * digit cannot be lost. Rates that need COMPARING (is a promo live?) go
 * through `percentToBp` instead; basis points remain the only unit for
 * rate arithmetic, never for showing a rate the API already sent as a
 * percent.
 */
export function formatRate(percent: string): string {
  return `${percent.replace(/\.?0+$/, '')}%`;
}

/** Masks an account number to its last four digits: "•••• 1234". */
export function maskAccountNo(accountNo: string): string {
  const tail = accountNo.slice(-4);
  return `•••• ${tail}`;
}

/** "2026-08-14" or ISO 8601 -> "14 Aug 2026". */
export function formatDate(value: string): string {
  return format(parseISO(value), 'd MMM yyyy');
}

/** Machine month "2026-03" -> "March 2026" (same date-fns styling as
 *  formatDate; the surrounding sentence is composed via i18n). */
export function formatMonthYear(value: string): string {
  return format(parseISO(`${value}-01`), 'MMMM yyyy');
}

/** Metres -> { km: "1.2" } or { m: 450 } for the distance strings. */
export function splitDistance(
  meters: number,
): { unit: 'm'; value: number } | { unit: 'km'; value: string } {
  if (meters < 1000) {
    return { unit: 'm', value: meters };
  }
  const tenths = Math.round(meters / 100);
  return { unit: 'km', value: `${Math.trunc(tenths / 10)}.${tenths % 10}` };
}

/**
 * Normalises user phone input to the API's +960 form: accepts "7123456",
 * "9601234567"-style or "+9607123456", with spaces/dashes tolerated.
 * Returns null when it cannot be a Maldivian mobile number.
 */
export function normalizeMaldivesPhone(input: string): string | null {
  const digits = input.replace(/[\s-]/g, '');
  const match = /^(?:\+?960)?([79]\d{6})$/.exec(digits);
  return match === null ? null : `+960${match[1]}`;
}
