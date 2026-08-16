import { format, parseISO } from 'date-fns';
import i18n from 'i18next';

/**
 * Display-only formatting helpers. Money arithmetic stays in integer laari
 * and rate arithmetic in integer basis points (`percentToBp`) everywhere —
 * floats appear only in non-monetary display maths (distances).
 */

/**
 * Unicode isolates. A run of digits and punctuation has no strong direction
 * of its own, so inside a Dhivehi (RTL) sentence the bidi algorithm is free
 * to reorder it — which is how "2%" reaches the screen as "%2" and how a
 * leading minus jumps to the wrong end. Wrapping the run in
 * LEFT-TO-RIGHT ISOLATE … POP DIRECTIONAL ISOLATE pins it, and works
 * everywhere a string goes: inside a translated sentence, an attribute, or
 * a bare text node, with no wrapper element to place.
 */
const LRI = '\u2066';
const PDI = '\u2069';

/** Pins a neutral run (digits, %, punctuation) to left-to-right order. */
export function ltrIsolate(text: string): string {
  return `${LRI}${text}${PDI}`;
}

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
  return ltrIsolate(`${percent.replace(/\.?0+$/, '')}%`);
}

/** Masks an account number to its last four digits: "•••• 1234". */
export function maskAccountNo(accountNo: string): string {
  const tail = accountNo.slice(-4);
  return ltrIsolate(`•••• ${tail}`);
}

/**
 * Dhivehi month names. date-fns ships no `dv` locale, and there is no
 * Intl data for it either — every runtime falls back to English — so the
 * twelve names live here.
 *
 * Numerals stay Latin: Thaana defines no digits of its own and Maldivian
 * dates are written with Latin figures everywhere, from a bank statement
 * to a newspaper. Only the month is translated.
 */
const MONTHS_DV = [
  'ޖެނުއަރީ',
  'ފެބްރުއަރީ',
  'މާޗް',
  'އެޕްރީލް',
  'މެއި',
  'ޖޫން',
  'ޖުލައި',
  'އޮގަސްޓް',
  'ސެޕްޓެމްބަރު',
  'އޮކްޓޫބަރު',
  'ނޮވެމްބަރު',
  'ޑިސެމްބަރު',
] as const;

/**
 * True when the app is currently in Dhivehi.
 *
 * Read off the i18next singleton rather than passed in. These formatters
 * are called from 22 places, several of them plain helpers rather than
 * components, and a language argument threaded through all of them is a
 * language argument that one call site forgets — printing an English month
 * inside a Dhivehi sentence, which is exactly the bug being fixed. The
 * singleton is the same instance useTranslation() reads, so the two can
 * never disagree.
 *
 * Re-render is not a concern: switching language re-renders the tree
 * through react-i18next, and these run during that render.
 */
function inDhivehi(): boolean {
  return i18n.language?.startsWith('dv') ?? false;
}

/** "2026-08-14" or ISO 8601 -> "14 Aug 2026", or the Dhivehi month. */
export function formatDate(value: string): string {
  const date = parseISO(value);

  if (inDhivehi()) {
    // Day and year keep their Latin figures; the whole run is isolated so
    // the bidi algorithm cannot reorder "14" and "2026" around the month.
    return ltrIsolate(
      `${date.getDate()} ${MONTHS_DV[date.getMonth()]} ${date.getFullYear()}`,
    );
  }

  return format(date, 'd MMM yyyy');
}

/** Machine month "2026-03" -> "March 2026", or the Dhivehi month. */
export function formatMonthYear(value: string): string {
  const date = parseISO(`${value}-01`);

  if (inDhivehi()) {
    return ltrIsolate(`${MONTHS_DV[date.getMonth()]} ${date.getFullYear()}`);
  }

  return format(date, 'MMMM yyyy');
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
