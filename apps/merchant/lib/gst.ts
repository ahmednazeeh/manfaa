import type { FeeTreatment } from '@manfaa/api-client';

/**
 * WHEN THE GST LINE APPEARS (owner, 2026-08-24).
 *
 * Manfaa's platform fee and the GST charged on that fee are two different
 * facts — one is what Manfaa charges, the other is a tax collected on
 * Manfaa's behalf — so every screen that tells a merchant what they owe
 * shows them as SEPARATE lines. The merchant owes cashback + fee + GST, and
 * a single blended "fees" figure lets them reconcile neither half.
 *
 * GST is switched off platform-wide today, and every stored `fee_gst_laari`
 * is 0. A row reading "GST  MVR 0.00" on every settlement, every board and
 * every invoice line would be pure noise about a tax that does not exist
 * yet, so the line is absent — not zeroed — until there is tax to show.
 *
 * The rule is deliberately the STORED AMOUNT and not a platform setting:
 *
 *   - The merchant panel is never told whether GST is enabled (that lives on
 *     the superadmin tax-settings route). Asking the amount is the only
 *     honest question this panel can ask.
 *   - The rate is STAMPED on each transaction at creation, so a batch priced
 *     before the switch keeps showing no GST after it is thrown, which is
 *     exactly right: that batch genuinely carries none.
 *   - A bill that legitimately carries no tax (a marketplace row, whose fee
 *     is Manfaa's marketplace cut) says nothing about GST either.
 *
 * Zero is compared against the SERVER's integer laari. Nothing here derives
 * a tax, applies a rate, or decides what is owed — it only decides whether a
 * figure the server already sent is worth a row on screen.
 */

/** Whether one GST figure (integer laari) is worth a line of its own. */
export function hasGst(feeGstLaari: number): boolean {
  return feeGstLaari !== 0;
}

/**
 * Whether a whole table needs its GST column — true as soon as ANY row
 * carries tax. Pass the rows' stored `fee_gst_laari` values.
 *
 * Judged over every row the table can draw, never over the current page: a
 * column that appeared and vanished as the merchant paged would read as a
 * bug, and one keyed off the first row alone would hide real tax on the
 * rows below it. On a server-paginated list "every row the table can draw"
 * is the page the API returned, which is the whole of what it can know.
 */
export function anyGst(feeGstLaari: readonly number[]): boolean {
  return feeGstLaari.some(hasGst);
}

/**
 * THE ESTIMATE'S HALF OF THE STORY (the round's own gap, 2026-08-25).
 *
 * Everything above answers "is a tax the server already computed worth a
 * row". This answers the other question, and only the cost preview asks it:
 * a sale that does not exist yet has no stamped tax to read, so the quote
 * prices from the LIVE policy the rate endpoint publishes
 * (`gst_rate_percent` + `fee_treatment`) — exactly what the server would
 * stamp a second later.
 *
 * The split is `App\Domain\Tax\FeeTax::split()`, laari for laari:
 *
 *   on_top     net = fee                    gst = ceil(fee × bp / 10000)
 *   inclusive  gst = ceil(fee × bp / (10000 + bp))    net = fee − gst
 *
 * Ceiling in both directions, the same rounding rule the fee itself is
 * computed with. At 0 bp — the platform today — this returns the fee
 * untouched with no tax under either treatment, so the quote is unchanged
 * until the day the switch is thrown.
 *
 * WHY THE ESTIMATE MUST DO THIS AT ALL. `platform_fee_percent` is the GROSS
 * rate under both treatments. Under `on_top` the merchant's bill is
 * cashback + fee + GST, so a quote of cashback + fee is short by the tax on
 * every sale from the moment a superadmin flips the switch — a runtime
 * change with no deploy behind it. Under `inclusive` the bill is unchanged
 * and the split only says how much of the quoted fee was tax.
 */
export function splitFeeForGst(
  feeLaari: number,
  gstRateBp: number,
  treatment: FeeTreatment,
): { fee: number; gst: number } {
  if (
    !Number.isSafeInteger(feeLaari) ||
    !Number.isSafeInteger(gstRateBp) ||
    gstRateBp <= 0 ||
    feeLaari <= 0
  ) {
    return { fee: feeLaari, gst: 0 };
  }

  if (treatment === 'on_top') {
    return { fee: feeLaari, gst: intDiv(feeLaari * gstRateBp + 9999, 10000) };
  }

  const divisor = 10000 + gstRateBp;
  const gst = intDiv(feeLaari * gstRateBp + divisor - 1, divisor);

  return { fee: feeLaari - gst, gst };
}

/** intdiv for non-negative safe integers — exact, never a float quotient. */
function intDiv(a: number, b: number): number {
  return (a - (a % b)) / b;
}
