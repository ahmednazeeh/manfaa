import { bpToPercentString } from '@manfaa/api-client';

/**
 * Display-only estimate math for the credit screen preview, mirroring the
 * API's §4 formula: cashback = intdiv(eligible * rate_bp + 9999, 10000),
 * i.e. ceiling division — every fractional laari rounds UP.
 *
 * Integer arithmetic only (no float division results are used directly):
 * the quotient is derived by subtracting the remainder, which is exact for
 * safe integers. The SERVER remains authoritative — it resolves the rate at
 * the sale time and freezes it onto the row; this preview always uses the
 * merchant's CURRENT rate and is labelled an estimate in the UI.
 */

/** intdiv(a, b) for non-negative safe integers, exact (no float rounding). */
function intDiv(a: number, b: number): number {
  return (a - (a % b)) / b;
}

/**
 * Ceiling laari amount for `eligibleLaari` at `bp` basis points. Throws on
 * non-integer input so a float can never sneak into money display.
 */
export function estimateLaariAtBp(eligibleLaari: number, bp: number): number {
  if (!Number.isSafeInteger(eligibleLaari) || !Number.isSafeInteger(bp)) {
    throw new TypeError('estimateLaariAtBp expects integer laari and bp');
  }
  return intDiv(eligibleLaari * bp + 9999, 10000);
}

/**
 * Integer basis points as a trimmed percent string: 275 -> "2.75%",
 * 500 -> "5%", 250 -> "2.5%". Built on the shared percent helper
 * (exact integer decomposition, never a float) with trailing zeros
 * dropped for display.
 */
export function formatBp(bp: number): string {
  return `${bpToPercentString(bp).replace(/\.?0+$/, '')}%`;
}
