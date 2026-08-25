/**
 * The regions of the panel a guided tour is allowed to point at.
 *
 * A tour step names one of these, and the overlay finds it with a plain
 * `[data-tour="…"]` query at the moment it needs to. Nothing here is a
 * ref, a portal or a registry, for one reason: the elements live in five
 * different files across the dashboard, the sidebar and a card that only
 * some accounts render, and a registry would need every one of them to
 * remember to unregister. A missing attribute is simply a step that does
 * not happen.
 *
 * THE ANCHOR IS THE CONTRACT. If a card is renamed, moved or gated behind
 * a new permission, carry `data-tour` with it or delete it — a tour step
 * whose anchor no longer exists is skipped silently (see guided-tour.tsx),
 * which is the failure mode we want, but the step's words are then lost
 * without anybody noticing.
 */
export const TOUR_ANCHORS = {
  /** The Quick actions card — where both journeys now start. */
  quickActions: 'quick-actions',
  /** Its "Credit customer" button, which opens the dialog in place. */
  quickActionsCredit: 'quick-actions-credit',
  /** Its "Settle now" button, which opens the settlement wizard. */
  quickActionsSettle: 'quick-actions-settle',
  /** The four ageing buckets, 0–5 days through Overdue. */
  ageingBuckets: 'ageing-buckets',
  /** The Overdue bucket alone — the one with a consequence attached. */
  overdueBucket: 'overdue-bucket',
  /** Total payable: cashback + fee (+ GST), and the count behind it. */
  totalPayable: 'total-payable',
  /** The wallet balance card, absent without `wallet.view`. */
  wallet: 'wallet-card',
  /** Sidebar rows. Absent on a phone, where there is no sidebar at all. */
  navCredit: 'nav-credit',
  navTransactions: 'nav-transactions',
  navSettlements: 'nav-settlements',
} as const;

export type TourAnchor = (typeof TOUR_ANCHORS)[keyof typeof TOUR_ANCHORS];

/** The attribute the overlay queries. One place, so it cannot drift. */
export const TOUR_ANCHOR_ATTRIBUTE = 'data-tour';

/**
 * Spread onto whatever element the step should highlight:
 * `<Card {...tourAnchor(TOUR_ANCHORS.quickActions)}>`.
 *
 * Typed against the catalogue so a mistyped anchor is a build failure
 * rather than a tour step that quietly never appears.
 */
export function tourAnchor(anchor: TourAnchor): { 'data-tour': TourAnchor } {
  return { 'data-tour': anchor };
}

/** The element a step points at, or null when it is not on this screen. */
export function findTourAnchor(anchor: TourAnchor): HTMLElement | null {
  if (typeof document === 'undefined') {
    return null;
  }

  const element = document.querySelector<HTMLElement>(
    `[${TOUR_ANCHOR_ATTRIBUTE}="${anchor}"]`,
  );

  if (element === null) {
    return null;
  }

  // Present in the DOM is not the same as on the screen. A collapsed
  // sidebar, a `hidden lg:flex` wrapper and a card mid-unmount all answer
  // the query while measuring 0×0, and a spotlight on a zero-sized box is
  // a black screen with a ring in the corner.
  const rect = element.getBoundingClientRect();

  return rect.width > 0 && rect.height > 0 ? element : null;
}
