import type { Settlement, SettlementPayment } from '@manfaa/api-client';

/**
 * Reading a batch the way the matching queue needs it: which receipt is under
 * review, what it claims against what is owed, and — for a batch that was
 * refused — the rejection that cancelled it.
 *
 * Every figure here is a sum of stored integer laari. Nothing is recomputed
 * from a rate and nothing passes through a float.
 */

/** The newest payment still awaiting a decision, i.e. the one under review. */
export function pendingPayment(
  settlement: Pick<Settlement, 'payments'>,
): SettlementPayment | null {
  const pending = (settlement.payments ?? []).filter(
    (payment) => payment.state === 'pending',
  );
  return pending.length === 0 ? null : pending[pending.length - 1];
}

/** The newest payment carrying an uploaded slip, whatever its state. */
export function slipPayment(
  settlement: Pick<Settlement, 'payments'>,
): SettlementPayment | null {
  const withSlip = (settlement.payments ?? []).filter(
    (payment) => payment.has_slip,
  );
  return withSlip.length === 0 ? null : withSlip[withSlip.length - 1];
}

/** Total claimed by payments still awaiting a decision, in integer laari. */
export function claimedLaari(settlement: Pick<Settlement, 'payments'>): number {
  return (settlement.payments ?? [])
    .filter((payment) => payment.state === 'pending')
    .reduce((sum, payment) => sum + payment.amount_laari, 0);
}

/**
 * The refusal that cancelled this batch, read off the payment the admin
 * rejected. A batch cancelled without any payment was never refused — it is a
 * plain cancellation and must not be dressed up as a rejection.
 */
export function rejection(
  settlement: Pick<Settlement, 'state' | 'payments'>,
): SettlementPayment | null {
  if (settlement.state !== 'cancelled') {
    return null;
  }
  const rejected = (settlement.payments ?? []).filter(
    (payment) => payment.state === 'rejected' && payment.rejection_reason,
  );
  return rejected.length === 0 ? null : rejected[rejected.length - 1];
}

/** Total still owed on the batch: what it is due less what has been received. */
export function outstandingLaari(
  settlement: Pick<Settlement, 'amount_due_laari' | 'amount_received_laari'>,
): number {
  return settlement.amount_due_laari - settlement.amount_received_laari;
}

/**
 * §7 forgiveness threshold: a remaining unpaid balance strictly under MVR 1
 * is absorbed by the platform. Exactly 100 laari is NOT forgiven.
 */
export const FORGIVENESS_THRESHOLD_LAARI = 100;

export interface ClaimComparison {
  kind: 'exact' | 'short' | 'over';
  /** Absolute gap in laari between the claim and what is outstanding. */
  deltaLaari: number;
  /** True when a shortfall is small enough for §7 forgiveness to close it. */
  forgivable: boolean;
}

/** The claimed transfer measured against what the batch still owes. */
export function compareClaim(
  claimedLaari: number,
  outstanding: number,
): ClaimComparison {
  const delta = claimedLaari - outstanding;

  if (delta === 0) {
    return { kind: 'exact', deltaLaari: 0, forgivable: false };
  }
  if (delta > 0) {
    return { kind: 'over', deltaLaari: delta, forgivable: false };
  }
  return {
    kind: 'short',
    deltaLaari: -delta,
    forgivable: -delta < FORGIVENESS_THRESHOLD_LAARI,
  };
}
