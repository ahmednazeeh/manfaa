import type { Settlement } from '@manfaa/api-client';

/**
 * What a matched payment actually did, derived by diffing the settlement
 * before and after the match. All figures are integer laari sums of stored
 * line integers — nothing is recomputed from rates.
 */
export interface MatchOutcome {
  state: Settlement['state'];
  /** Lines newly allocated by this match. */
  allocatedCount: number;
  /** Sum of due_laari over the newly allocated lines. */
  allocatedLaari: number;
  /** Sub-MVR-1 shortfall absorbed by the platform (§7 forgiveness rule). */
  forgivenLaari: number;
  /**
   * Net movement of the merchant wallet attributable to this match: positive
   * when leftover cash was parked as merchant credit (overpayment or a
   * partial's remainder), negative when a previously parked remainder was
   * consumed by newly covered lines.
   */
  walletDeltaLaari: number;
}

function allocatedTotal(settlement: Settlement): number {
  return (settlement.lines ?? [])
    .filter((line) => line.allocated_at !== null)
    .reduce((sum, line) => sum + line.due_laari, 0);
}

function forgivenTotal(settlement: Settlement): number {
  // Forgiveness only ever fires on the final match that settles the batch:
  // the platform absorbs the gap between what all lines were due and what
  // was actually received.
  if (settlement.state !== 'settled') {
    return 0;
  }
  return Math.max(
    0,
    settlement.amount_due_laari - settlement.amount_received_laari,
  );
}

/**
 * Wallet position attributable to the settlement at a point in time:
 * received cash minus the cash actually consumed by allocated lines
 * (allocated total net of any forgiven laari, which no cash covered).
 */
function walletPosition(settlement: Settlement): number {
  return (
    settlement.amount_received_laari -
    (allocatedTotal(settlement) - forgivenTotal(settlement))
  );
}

export function computeMatchOutcome(
  before: Settlement,
  after: Settlement,
): MatchOutcome {
  const previouslyAllocated = new Set(
    (before.lines ?? [])
      .filter((line) => line.allocated_at !== null)
      .map((line) => line.id),
  );

  const newlyAllocated = (after.lines ?? []).filter(
    (line) => line.allocated_at !== null && !previouslyAllocated.has(line.id),
  );

  return {
    state: after.state,
    allocatedCount: newlyAllocated.length,
    allocatedLaari: newlyAllocated.reduce(
      (sum, line) => sum + line.due_laari,
      0,
    ),
    forgivenLaari: forgivenTotal(after) - forgivenTotal(before),
    walletDeltaLaari: walletPosition(after) - walletPosition(before),
  };
}
