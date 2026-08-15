import type { Settlement } from '@manfaa/api-client';
import {
  allocatedTotalLaari,
  discountConsumedLaari,
  nonCashFundedLaari,
} from './discount';

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
   * Prompt-payment discount (PLAN §1) consumed by this match — covered funds
   * exactly like an applied credit, posted DR platform fee revenue / CR
   * merchant receivable at allocation, not at submit.
   */
  discountAppliedLaari: number;
  /** The rate that discount was granted at, null when none was. */
  discountRateBp: number | null;
  /**
   * Net movement of the merchant wallet attributable to this match: positive
   * when leftover cash was parked as merchant credit (overpayment or a
   * partial's remainder), negative when a previously parked remainder was
   * consumed by newly covered lines.
   */
  walletDeltaLaari: number;
}

function forgivenTotal(settlement: Settlement): number {
  // Forgiveness only ever fires on the final match that settles the batch:
  // the platform absorbs the gap between what the batch was due and what was
  // actually received. The due is already net of the discount, so a merchant
  // who transferred the discounted amount in full forgives nothing.
  if (settlement.state !== 'settled') {
    return 0;
  }
  return Math.max(
    0,
    settlement.amount_due_laari - settlement.amount_received_laari,
  );
}

/**
 * Wallet position attributable to the settlement at a point in time: received
 * cash minus the cash actually consumed by allocated lines.
 *
 * The allocated total is a sum of full line dues, and lines are funded from
 * four sources in a fixed order — §7 credit adjustments, the prompt-payment
 * discount, cash, then (only under MVR 1) platform forgiveness. Only the cash
 * leg moves the wallet, so the non-cash funding has to come off the allocated
 * total first; otherwise a discounted or credit-netted batch reads as though
 * it had eaten parked merchant credit that never existed.
 */
function walletPosition(settlement: Settlement): number {
  return (
    settlement.amount_received_laari -
    (allocatedTotalLaari(settlement) -
      nonCashFundedLaari(settlement) -
      forgivenTotal(settlement))
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
    discountAppliedLaari:
      discountConsumedLaari(after) - discountConsumedLaari(before),
    discountRateBp: after.discount_rate_bp,
    walletDeltaLaari: walletPosition(after) - walletPosition(before),
  };
}
