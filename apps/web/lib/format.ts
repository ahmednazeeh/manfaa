import { format, parseISO } from 'date-fns';

/**
 * Display-only formatting helpers. Money arithmetic stays in integer laari
 * and rates stay in integer basis points everywhere — floats appear only in
 * non-monetary display maths (distances).
 */

/** Integer basis points -> percent string: 200 -> "2%", 550 -> "5.5%". */
export function formatRateBp(bp: number): string {
  if (!Number.isSafeInteger(bp)) {
    throw new TypeError(`rate_bp must be a safe integer, got ${bp}`);
  }
  const whole = Math.trunc(bp / 100);
  const frac = bp % 100;
  if (frac === 0) {
    return `${whole}%`;
  }
  if (frac % 10 === 0) {
    return `${whole}.${frac / 10}%`;
  }
  return `${whole}.${String(frac).padStart(2, '0')}%`;
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
