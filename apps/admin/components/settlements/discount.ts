import type { Settlement } from '@manfaa/api-client';

/**
 * The PLAN §1 prompt-payment discount as the matching queue has to read it:
 * how a batch arrived at its amount due, and how much of the discount has
 * already funded allocations.
 *
 * Two things a matcher needs and cannot see from the line table alone:
 *
 *  - **why the due is lower than the line sum.** A batch's amount due is the
 *    line total less §7 credit adjustments less the discount. Both reductions
 *    are batch-level; neither touches a line's stored `due_laari`, so the
 *    lines add up to more than the merchant was asked to transfer, and that
 *    gap has to be explained rather than discovered.
 *  - **that the discount is covered funds.** The allocator consumes it
 *    exactly like an applied credit — adjustment credits first, the discount
 *    second, cash last — so a merchant who transfers the discounted amount
 *    settles every line with no residue and no forgiveness. An outcome
 *    summary that ignored it would read the discount as merchant credit
 *    consumed out of the wallet, which is not what happened.
 *
 * Every figure is a sum of stored integer laari. The discount is the server's
 * granted number, never re-derived here from a rate.
 */

export interface BatchPrice {
  /** Sum of every line's due — the batch at full price. */
  lineTotalLaari: number;
  /**
   * §7 reversal memos netted onto the batch at draft time. Not published as
   * its own field, but it is exactly what the line total was reduced by that
   * the discount does not account for.
   */
  creditAppliedLaari: number;
  /** The prompt-payment discount granted at submit (0 when refused). */
  discountLaari: number;
  /** What the merchant was actually asked to transfer. */
  amountDueLaari: number;
}

/**
 * The batch's price, reconciled. Null when the endpoint did not load the
 * lines — without them the line total is unknown and nothing can be
 * reconstructed by guessing.
 */
export function batchPrice(settlement: Settlement): BatchPrice | null {
  const lines = settlement.lines;

  if (lines === undefined) {
    return null;
  }

  const lineTotal = lines.reduce((sum, line) => sum + line.due_laari, 0);
  const discount = settlement.discount_laari;

  return {
    lineTotalLaari: lineTotal,
    creditAppliedLaari: Math.max(
      0,
      lineTotal - settlement.amount_due_laari - discount,
    ),
    discountLaari: discount,
    amountDueLaari: settlement.amount_due_laari,
  };
}

/** Sum of `due_laari` over the lines this batch has already allocated. */
export function allocatedTotalLaari(settlement: Settlement): number {
  return (settlement.lines ?? [])
    .filter((line) => line.allocated_at !== null)
    .reduce((sum, line) => sum + line.due_laari, 0);
}

/**
 * How much of the granted discount has funded allocations so far — the same
 * number the ledger posted as DR platform fee revenue / CR merchant
 * receivable, derived from the fixed consumption order: adjustment credits
 * first (their journal posted back at application), then the discount, then
 * cash.
 */
export function discountConsumedLaari(settlement: Settlement): number {
  const price = batchPrice(settlement);

  if (price === null) {
    return 0;
  }

  const pastTheCredits =
    allocatedTotalLaari(settlement) - price.creditAppliedLaari;

  return Math.min(price.discountLaari, Math.max(0, pastTheCredits));
}

/**
 * Everything funding the allocated lines that was never cash: the pre-posted
 * §7 credit plus the discount, both consumed ahead of any money. Subtracting
 * it is what stops a discounted batch from looking like it ate merchant
 * wallet credit.
 */
export function nonCashFundedLaari(settlement: Settlement): number {
  const price = batchPrice(settlement);

  if (price === null) {
    return 0;
  }

  return Math.min(
    allocatedTotalLaari(settlement),
    price.creditAppliedLaari + price.discountLaari,
  );
}
