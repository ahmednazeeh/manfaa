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

/**
 * The §4 STATIC fee bands, for display-only estimates where the server's
 * schedule-resolved fee is not at hand (e.g. per-line previews on the
 * credit screen). The platform's fee tier schedule remains authoritative —
 * the server resolves the actual per-line fee at credit time.
 */
const STATIC_FEE_BANDS: ReadonlyArray<{ to: number; fee: number }> = [
  { to: 99, fee: 25 },
  { to: 199, fee: 50 },
  { to: 499, fee: 75 },
  { to: 2000, fee: 100 },
];

/** Estimated fee bp for a cashback rate, from the §4 static bands. */
export function estimateFeeBpFor(rateBp: number): number {
  const band = STATIC_FEE_BANDS.find(({ to }) => rateBp <= to);
  return (band ?? STATIC_FEE_BANDS[STATIC_FEE_BANDS.length - 1]).fee;
}
