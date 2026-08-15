/**
 * The ONE translation between the wire (2-decimal percent) and rate
 * arithmetic (integer basis points) — the client mirror of the API's
 * App\Domain\Money\Percent.
 *
 * WIRE FORMAT (PLAN §1, decision 2026-08-15): `rate_bp` / `fee_bp` never
 * appear in a request or a response. Every rate on the wire is a 2-decimal
 * percent STRING — "2.00", "0.75", "12.50" — the same idiom `cashback_mvr`
 * already uses. Requests may also send a JSON number with at most 2
 * decimals (2, 2.5); the server converts by exact integer math.
 *
 * Basis points remain the only sane unit for ARITHMETIC (comparing rates,
 * spotting the §4 tier cliff, driving a slider), so this module converts
 * both ways — always by string decomposition, never through float math:
 * the value is split on the dot and each half read as an integer. A JS
 * number is turned into its shortest round-trip text FIRST (`String(2.5)`
 * is exactly "2.5"), so a value that is not really a 2-decimal number
 * (0.1 + 0.2 → "0.30000000000000004") is refused rather than silently
 * rounded — the same proof the server performs on a JSON float.
 */

/**
 * A rate as the API emits it: digits, a dot, exactly two decimals. Never
 * signed — a rate is never negative (see the delta grammar below).
 */
const PERCENT_PATTERN = /^\d+\.\d{2}$/;

/** A DIFFERENCE between two rates, which may legitimately be negative. */
const PERCENT_DELTA_PATTERN = /^-?\d+\.\d{2}$/;

/**
 * The looser REQUEST grammar: what a caller may send. "2", "2.5", "2.50",
 * a trailing % (stripped for a form field that shows one), or the JSON
 * numbers 2 / 2.5. At most two decimals, never negative.
 */
const PERCENT_INPUT_PATTERN = /^(\d+)(?:\.(\d{1,2}))?$/;

/** True for a canonical wire percent — "2.00", "0.75", "12.50". */
export function isPercentString(value: string): boolean {
  return PERCENT_PATTERN.test(value);
}

/** True for a canonical wire rate DELTA — "0.26", "-0.26". */
export function isPercentDeltaString(value: string): boolean {
  return PERCENT_DELTA_PATTERN.test(value);
}

/**
 * Parses a percent a CALLER supplied ("2", "2.5", "0.75", 2.5, optionally
 * with a trailing %) into integer basis points via string decomposition.
 *
 * Returns null for anything else — more than two decimals, negatives,
 * empty, stray characters, a float that is not exactly a 2-decimal value.
 * Range rules (the §4 0.50%–20.00% window) belong to the caller: "20.01"
 * parses to 2001 and is rejected there.
 */
export function parsePercentToBp(input: string | number): number | null {
  const text = typeof input === 'number' ? decimalText(input) : input;
  if (text === null) {
    return null;
  }
  const normalized = text.trim().replace(/%$/, '').trim();
  const match = PERCENT_INPUT_PATTERN.exec(normalized);
  if (match === null) {
    return null;
  }
  const [, whole, fraction = ''] = match;
  const bp = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
  return Number.isSafeInteger(bp) ? bp : null;
}

/**
 * The predicate the request schemas ask: is this a percent the API's wire
 * grammar accepts, within [minBp, maxBp]? Mirrors App\Rules\PercentRate, so
 * a bad value is a form error here instead of a 422 there.
 */
export function isPercentInput(
  input: string | number,
  minBp: number,
  maxBp: number,
): boolean {
  const bp = parsePercentToBp(input);
  return bp !== null && bp >= minBp && bp <= maxBp;
}

/**
 * A percent the API EMITTED -> integer basis points, for arithmetic only
 * ("2.00" -> 200, "0.75" -> 75). Strict on purpose: the server always
 * emits the canonical 2-decimal form, so anything else is a contract
 * breach worth throwing on rather than rendering as a wrong number.
 */
export function percentToBp(percent: string): number {
  if (!PERCENT_PATTERN.test(percent)) {
    throw new TypeError(`Not a 2-decimal percent string: ${percent}`);
  }
  const [whole, fraction] = percent.split('.');
  const bp = Number(whole) * 100 + Number(fraction);
  if (!Number.isSafeInteger(bp)) {
    throw new TypeError(`Percent out of safe range: ${percent}`);
  }
  return bp;
}

/**
 * percentToBp for a signed rate DELTA — "-0.26" -> -26. Deltas are the one
 * rate value that may be negative (a promotion cheaper than the standing
 * terms, a fee tier dropping).
 */
export function percentDeltaToBp(percent: string): number {
  if (!PERCENT_DELTA_PATTERN.test(percent)) {
    throw new TypeError(`Not a 2-decimal percent delta string: ${percent}`);
  }
  return percent.startsWith('-')
    ? -percentToBp(percent.slice(1))
    : percentToBp(percent);
}

/** 75 -> "0.75" — plain percent value for form inputs, no % sign. */
export function bpToPercentString(bp: number): string {
  if (!Number.isSafeInteger(bp)) {
    throw new TypeError(`bp must be a safe integer, got ${bp}`);
  }
  const sign = bp < 0 ? '-' : '';
  const abs = Math.abs(bp);
  return `${sign}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** 75 -> "0.75%" — display form. Integer decomposition, no float formatting. */
export function formatBpPercent(bp: number): string {
  return `${bpToPercentString(bp)}%`;
}

/**
 * A wire percent as display text: "2.00" -> "2.00%". The string the API
 * sent is already exact, so it is passed through verbatim — no round trip
 * through a number, and therefore no way to lose a digit.
 */
export function formatPercent(percent: string): string {
  if (!PERCENT_DELTA_PATTERN.test(percent)) {
    throw new TypeError(`Not a 2-decimal percent string: ${percent}`);
  }
  return `${percent}%`;
}

/**
 * The shortest text that round-trips a JS number, or null when the number
 * cannot be a 2-decimal value at all. `String(2.5)` is exactly "2.5" —
 * JavaScript's number->string is the shortest representation that parses
 * back identically, which is precisely the proof the server's `%.2F`
 * round-trip performs. No arithmetic is ever done on the float.
 */
function decimalText(value: number): string | null {
  return Number.isFinite(value) ? String(value) : null;
}
