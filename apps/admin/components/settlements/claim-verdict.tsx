'use client';

import { formatMoney } from '@manfaa/ui';
import { CircleCheck, Info, TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertIcon } from '@/components/ui/alert';
import { compareClaim } from './receipt';

/**
 * What matching THIS FIGURE will do to the batch, stated before the admin
 * commits to it. Purely §7: whole lines oldest-first, sub-MVR-1 forgiveness,
 * overpayment parked as merchant wallet credit.
 *
 * It measures cash against cash — the outstanding figure is the batch's own
 * due, already net of any prompt-payment discount and §7 credits, and any
 * merchant credit parked in the wallet is counted on top at match time, so a
 * shortfall shown here is the worst case.
 *
 * THE FIGURE IS NOT ALWAYS THE CLAIM (owner, 2026-08-25). What allocates is
 * what the BANK sent; the merchant's typed amount is only the best guess
 * until a statement says otherwise. So this takes the amount as a parameter
 * and the caller decides which one it is holding — the review card shows the
 * verdict for the figure on the row, and the match dialog re-computes it live
 * as the reviewer types what the statement actually says.
 */
export function ClaimVerdict({
  amountLaari,
  outstanding,
}: {
  /** The cash being matched: the bank's figure where one is known. */
  amountLaari: number;
  /** What the batch still owes. */
  outstanding: number;
}) {
  const comparison = compareClaim(amountLaari, outstanding);

  if (comparison.kind === 'exact') {
    return (
      <Alert variant="success" appearance="light" size="sm">
        <AlertIcon>
          <CircleCheck />
        </AlertIcon>
        <AlertDescription>
          {formatMoney(amountLaari)} matches the amount outstanding exactly —
          matching allocates every line and settles the batch.
        </AlertDescription>
      </Alert>
    );
  }

  if (comparison.kind === 'over') {
    return (
      <Alert variant="info" appearance="light" size="sm">
        <AlertIcon>
          <Info />
        </AlertIcon>
        <AlertDescription>
          {formatMoney(comparison.deltaLaari)} more than outstanding. Matching
          allocates every line; the excess is parked as merchant wallet credit
          for the next batch — never refunded.
        </AlertDescription>
      </Alert>
    );
  }

  if (comparison.forgivable) {
    return (
      <Alert variant="info" appearance="light" size="sm">
        <AlertIcon>
          <Info />
        </AlertIcon>
        <AlertDescription>
          {formatMoney(comparison.deltaLaari)} short — under MVR 1, so matching
          forgives the gap (platform-funded), allocates every line and settles
          the batch in full.
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <Alert variant="warning" appearance="light" size="sm">
      <AlertIcon>
        <TriangleAlert />
      </AlertIcon>
      <AlertDescription>
        {formatMoney(comparison.deltaLaari)} short of the amount outstanding.
        Matching confirms whole transactions oldest-first and leaves the
        uncovered lines pending — never pro-rata.
      </AlertDescription>
    </Alert>
  );
}
