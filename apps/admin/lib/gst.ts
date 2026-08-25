import {
  formatBpPercent,
  FEE_TREATMENTS,
  GST_RATE_MAX_BP,
  GST_RATE_MIN_BP,
  type FeeTreatment,
  type TaxIdentityField,
} from '@manfaa/api-client';

/**
 * The panel-side vocabulary for GST on the platform fee, and the one copy of
 * its arithmetic used for the worked examples on the settings screen.
 *
 * The numbers here are DISPLAY ONLY. Nothing on this screen prices a sale —
 * the server splits every fee at creation and stamps the terms onto the row,
 * and this module exists so an operator can see what a rule will do to a
 * merchant's bill before they throw the switch. It mirrors
 * App\Domain\Tax\FeeTax exactly so the example is not a friendly
 * approximation of a different sum.
 */

/** The rate's bounds as the API states them (0.00% – 20.00%). */
export function gstRateBounds(): {
  minBp: number;
  maxBp: number;
  minLabel: string;
  maxLabel: string;
} {
  return {
    minBp: GST_RATE_MIN_BP,
    maxBp: GST_RATE_MAX_BP,
    minLabel: formatBpPercent(GST_RATE_MIN_BP),
    maxLabel: formatBpPercent(GST_RATE_MAX_BP),
  };
}

/**
 * `missing_identity_fields` arrives as REQUEST KEYS. An operator reads form
 * labels, not JSON keys, so the refusal is repeated back in the words that
 * sit above the inputs they have to fill.
 */
const IDENTITY_FIELD_LABELS: Record<TaxIdentityField, string> = {
  gst_tin: 'GST TIN',
  gst_business_name: 'registered business name',
  gst_activity_number: 'tax activity number',
};

export function gstIdentityFieldLabel(field: TaxIdentityField): string {
  return IDENTITY_FIELD_LABELS[field];
}

export interface GstTreatmentChoice {
  value: FeeTreatment;
  /** The choice as a decision an operator makes. */
  title: string;
  /** Who ends up paying the tax, in plain words. */
  help: string;
  /**
   * The rule as a clause, for a sentence that has already said "priced with
   * GST" — what the tax then does to the bill.
   */
  merchantEffect: string;
  /** The rule as a STATEMENT of what the platform currently does. */
  policyLine: string;
}

/**
 * The two treatments, in policy order. Deliberately NOT a toggle: neither is
 * an "off" state, and the difference is not degree but who pays — the
 * merchant, or Manfaa out of its own fee income.
 */
const TREATMENT_COPY: Record<FeeTreatment, Omit<GstTreatmentChoice, 'value'>> =
  {
    on_top: {
      title: 'Charge GST on top of the fee',
      help: 'Merchants pay fee + GST. Their bill goes up by the tax and Manfaa’s fee income is exactly what it was.',
      merchantEffect:
        'added on top, so the merchant’s bill goes up by the tax and Manfaa’s fee income is unchanged',
      policyLine: 'GST is charged on top of the fee',
    },
    inclusive: {
      title: 'Fee is GST-inclusive',
      help: 'Merchants pay the same as they do today; the GST portion comes out of Manfaa’s fee income.',
      merchantEffect:
        'carved out of the fee, so the merchant’s bill is unchanged and Manfaa’s fee income drops by the tax',
      policyLine: 'The fee is GST-inclusive',
    },
  };

export const gstTreatmentChoices: GstTreatmentChoice[] = FEE_TREATMENTS.map(
  (value) => ({ value, ...TREATMENT_COPY[value] }),
);

export function gstTreatmentPolicyLine(treatment: FeeTreatment): string {
  return TREATMENT_COPY[treatment].policyLine;
}

/**
 * A priced fee split into what Manfaa keeps and what it owes MIRA — the
 * client mirror of `App\Domain\Tax\FeeTax::split()`.
 *
 * Integer laari throughout, CEILING rounding in BOTH directions (a tax
 * authority is never short-changed by a rounding rule, and under `inclusive`
 * it is the platform's own revenue that absorbs the fraction). Zero is the
 * identity: at 0 bp both treatments return the fee untouched, which is why a
 * historical row re-priced from its own stamp reproduces itself exactly.
 */
export function splitFeeForGst(
  feeLaari: number,
  rateBp: number,
  treatment: FeeTreatment,
): { net: number; gst: number; merchantPays: number } {
  if (rateBp <= 0 || feeLaari <= 0) {
    return { net: feeLaari, gst: 0, merchantPays: feeLaari };
  }

  if (treatment === 'on_top') {
    // ceil(fee * bp / 10000) — the same expression the fee itself is rounded
    // with. The merchant's bill goes up by exactly this.
    const gst = Math.floor((feeLaari * rateBp + 9999) / 10000);
    return { net: feeLaari, gst, merchantPays: feeLaari + gst };
  }

  // ceil(fee * bp / (10000 + bp)) — the tax is a share of a GST-inclusive
  // amount, so the divisor is the inclusive base. The merchant pays the same
  // fee they always did; the tax is carved out of our revenue.
  const divisor = 10000 + rateBp;
  const gst = Math.floor((feeLaari * rateBp + divisor - 1) / divisor);
  return { net: feeLaari - gst, gst, merchantPays: feeLaari };
}

/**
 * WHEN A GST FIGURE IS WORTH A ROW (the rule the merchant panel and the app
 * already follow — apps/merchant/lib/gst.ts, mobile/merchant).
 *
 * GST is switched off platform-wide today and every stored `fee_gst_laari`
 * is 0, so a "GST MVR 0.00" hint on every fee tile and a column of zeros on
 * every settlement is noise about a tax that does not exist. The figure is
 * ABSENT until there is tax to show — and three surfaces describing one
 * batch must not disagree about whether nothing deserves a row.
 *
 * The test is the SERVER's stored integer, never a platform setting: a batch
 * priced before the switch was thrown genuinely carries no tax after it, and
 * that is exactly what its stored zeros say.
 */
export function hasGst(feeGstLaari: number): boolean {
  return feeGstLaari !== 0;
}

/**
 * Whether a whole table needs its GST column — true as soon as ANY row
 * carries tax, judged over every row the table can draw. A column that
 * appeared and vanished row by row would read as a bug.
 */
export function anyGst(feeGstLaari: readonly number[]): boolean {
  return feeGstLaari.some(hasGst);
}
