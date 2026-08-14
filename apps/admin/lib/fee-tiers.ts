import {
  bpToPercentString,
  formatBpPercent,
  parsePercentToBp,
  type FeeTierBand,
} from '@manfaa/api-client';

/**
 * Client-side mirror of the server's §4 tier rules (Domain\Money\TierSchedule
 * and Domain\Platform\TierScheduleService). The server remains authoritative
 * — this only lets the form flag the exact violation per row before submit.
 *
 * Humans type percent strings (up to 2 decimals); everything else is integer
 * basis points and integer laari. Conversion goes through the shared
 * percent.ts helpers — never a float.
 */

export { bpToPercentString, formatBpPercent, parsePercentToBp };

/** Coverage always starts at 0.50% cashback. */
export const TIER_RANGE_MIN_BP = 50;

/**
 * Absolute ceiling: no band may extend past 20.00%. A schedule's own ceiling
 * is its last band's `to_bp` — it need not reach 2000, and rates above it
 * are refused (`rate_not_priced`) until a wider schedule takes effect.
 */
export const TIER_RANGE_MAX_BP = 2000;

/** Server-enforced minimum lead time for a new schedule's effective_from. */
export const MINIMUM_LEAD_TIME_MINUTES = 60;

/** §4 ceiling: intdiv(eligible * bp + 9999, 10000) — integer-safe here. */
export function ceilingBpProduct(eligibleLaari: number, bp: number): number {
  return Math.floor((eligibleLaari * bp + 9999) / 10000);
}

/** One editable row of the schedule form — raw percent strings from the inputs. */
export interface TierRowInput {
  from_pct: string;
  to_pct: string;
  fee_pct: string;
}

/** Per-field messages for one row; absent field = valid. */
export interface TierRowIssues {
  from_pct?: string;
  to_pct?: string;
  fee_pct?: string;
}

export interface TierValidation {
  /** Non-null only when every rule passes. */
  bands: FeeTierBand[] | null;
  /** Parallel to the input rows. */
  rowIssues: TierRowIssues[];
  /** Whole-table failure, when no single row carries it. */
  scheduleError: string | null;
}

const PERCENT_FORMAT_MESSAGE = 'Percent with up to 2 decimals, e.g. 0.75.';

/**
 * Mirrors TierSchedule::fromArray row by row: percent strings parsed to
 * integer bp (at most 2 decimals), from <= to, ascending and contiguous from
 * 0.50%, no band past the 20.00% absolute ceiling, fee at least 0.01% and
 * never above the band's lowest cashback rate. Coverage runs from 0.50% to
 * the schedule's own last band — its end is the highest sellable rate.
 */
export function validateTierRows(rows: TierRowInput[]): TierValidation {
  const rowIssues: TierRowIssues[] = rows.map(() => ({}));
  const bands: FeeTierBand[] = [];
  let scheduleError: string | null = null;
  // null once a broken row makes contiguity unknowable for later rows.
  let expectedFrom: number | null = TIER_RANGE_MIN_BP;
  let valid = rows.length > 0;

  if (rows.length === 0) {
    scheduleError = 'Add at least one band.';
  }

  rows.forEach((row, index) => {
    const issues = rowIssues[index];
    const from = parsePercentToBp(row.from_pct);
    const to = parsePercentToBp(row.to_pct);
    const fee = parsePercentToBp(row.fee_pct);

    if (from === null) {
      issues.from_pct = PERCENT_FORMAT_MESSAGE;
    }
    if (to === null) {
      issues.to_pct = PERCENT_FORMAT_MESSAGE;
    }
    if (fee === null) {
      issues.fee_pct = PERCENT_FORMAT_MESSAGE;
    }

    if (from !== null && to !== null && from > to) {
      issues.to_pct = `Must be at least ${formatBpPercent(from)} — a band cannot end before it starts.`;
    }

    if (from !== null && expectedFrom !== null && from !== expectedFrom) {
      issues.from_pct =
        index === 0
          ? `Must start at ${formatBpPercent(TIER_RANGE_MIN_BP)} — coverage begins at the minimum cashback rate.`
          : `Must be ${formatBpPercent(expectedFrom)} (previous band's end + 0.01) — no gaps or overlaps.`;
    }

    if (to !== null && to > TIER_RANGE_MAX_BP) {
      issues.to_pct = `Cannot exceed the ${formatBpPercent(TIER_RANGE_MAX_BP)} absolute ceiling.`;
    }

    if (fee !== null && fee < 1) {
      issues.fee_pct = 'Must be at least 0.01%.';
    }

    if (fee !== null && from !== null && fee > from) {
      issues.fee_pct = `Cannot exceed ${formatBpPercent(from)} — the fee may never exceed the cashback rate it is charged on.`;
    }

    if (Object.keys(issues).length > 0) {
      valid = false;
    }

    if (from === null || to === null || from > to || to > TIER_RANGE_MAX_BP) {
      expectedFrom = null;
    } else {
      if (
        valid &&
        fee !== null &&
        expectedFrom !== null &&
        from === expectedFrom
      ) {
        bands.push({ from_bp: from, to_bp: to, fee_bp: fee });
      }
      expectedFrom = to + 1;
    }
  });

  return {
    bands: valid && scheduleError === null ? bands : null,
    rowIssues,
    scheduleError,
  };
}

/** Resolves the fee for a rate under a band list; null when uncovered. */
export function feeBpFor(bands: FeeTierBand[], rateBp: number): number | null {
  for (const band of bands) {
    if (rateBp >= band.from_bp && rateBp <= band.to_bp) {
      return band.fee_bp;
    }
  }
  return null;
}

/**
 * The §4 test fixture: four invoices at 200 bp cashback. Previews recompute
 * fee lines under a candidate schedule with the same ceiling rounding —
 * round at the line, then sum.
 */
export const SECTION4_FIXTURE_RATE_BP = 200;

export const SECTION4_FIXTURE = [
  { invoice: 'INV-1001', eligible_laari: 100000 },
  { invoice: 'INV-1002', eligible_laari: 50000 },
  { invoice: 'INV-1003', eligible_laari: 200000 },
  { invoice: 'INV-1004', eligible_laari: 80000 },
] as const;

export interface FixtureLine {
  invoice: string;
  eligible_laari: number;
  cashback_laari: number;
  fee_laari: number;
  due_laari: number;
}

export interface FixturePreview {
  fee_bp: number;
  lines: FixtureLine[];
  totals: Omit<FixtureLine, 'invoice'>;
}

/** Computes the §4 example under the given bands; null when 200 bp is uncovered. */
export function section4Preview(bands: FeeTierBand[]): FixturePreview | null {
  const feeBp = feeBpFor(bands, SECTION4_FIXTURE_RATE_BP);
  if (feeBp === null) {
    return null;
  }

  const lines: FixtureLine[] = SECTION4_FIXTURE.map((row) => {
    const cashback = ceilingBpProduct(
      row.eligible_laari,
      SECTION4_FIXTURE_RATE_BP,
    );
    const fee = ceilingBpProduct(row.eligible_laari, feeBp);
    return {
      invoice: row.invoice,
      eligible_laari: row.eligible_laari,
      cashback_laari: cashback,
      fee_laari: fee,
      due_laari: cashback + fee,
    };
  });

  const totals = lines.reduce(
    (sum, line) => ({
      eligible_laari: sum.eligible_laari + line.eligible_laari,
      cashback_laari: sum.cashback_laari + line.cashback_laari,
      fee_laari: sum.fee_laari + line.fee_laari,
      due_laari: sum.due_laari + line.due_laari,
    }),
    { eligible_laari: 0, cashback_laari: 0, fee_laari: 0, due_laari: 0 },
  );

  return { fee_bp: feeBp, lines, totals };
}
