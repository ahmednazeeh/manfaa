/**
 * Conversion between human percent strings and integer basis points.
 *
 * All rate arithmetic stays in integer basis points (1% = 100 bp), matching
 * the API's integer-bp law. Percent strings are decomposed with integer
 * math — the combined value never passes through parseFloat/Number.
 */

/**
 * Parses a user-entered percent ("2", "2.5", "0.75", optionally with a
 * trailing %) into integer basis points via string decomposition: split on
 * the dot, at most two decimals, pad-right, integer math per part.
 *
 * Returns null for anything else — more than two decimals, negatives,
 * empty, stray characters. Range rules (e.g. the 0.50%–20.00% window)
 * belong to the caller: "20.01" parses to 2001 and is rejected there.
 */
export function parsePercentToBp(input: string): number | null {
  const normalized = input.trim().replace(/%$/, '').trim();
  const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(normalized);
  if (match === null) {
    return null;
  }
  const [, whole, fraction = ''] = match;
  const bp = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
  return Number.isSafeInteger(bp) ? bp : null;
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
