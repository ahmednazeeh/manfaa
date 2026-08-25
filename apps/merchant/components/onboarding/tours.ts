import type { MerchantPermission } from '@manfaa/api-client';
import { TOUR_ANCHORS, type TourAnchor } from './anchors';

/**
 * The two walkthroughs the owner asked for (2026-08-25): "How to credit a
 * customer" and "How to settle due bills", each stepping through the real
 * regions of the dashboard rather than describing them in a modal.
 *
 * Both start at Quick actions, because that is where both journeys now
 * start — the card is the till's front door, and a tour that opened
 * somewhere else would be teaching a route nobody takes.
 *
 * Every step is a POINTER, never a driver: the tour highlights and
 * explains, and it never presses anything on the merchant's behalf. A tour
 * that clicked "Credit customer" would open a form over a live store with a
 * real customer's cashback behind it.
 */

export type TourId = 'credit' | 'settle';

export interface TourStepDefinition {
  /** The region this step highlights; absent anchors are skipped. */
  anchor: TourAnchor;
  /** `<key>.title` and `<key>.body` in both locale files. */
  i18nKey: string;
}

export interface TourDefinition {
  id: TourId;
  /** The tour's own name, as offered in the tasklist and the prompt. */
  i18nKey: string;
  /**
   * The permission that makes this journey THIS person's to walk. A
   * cashier who cannot settle is not offered a tour of settling; the tour
   * would be four highlights of controls that are not on their screen.
   *
   * Deliberately the same slug the matching tasklist row carries, so the
   * two can never disagree about whose job this is.
   */
  permission: MerchantPermission;
  steps: TourStepDefinition[];
}

export const TOURS: readonly TourDefinition[] = [
  {
    id: 'credit',
    i18nKey: 'onboarding.tour.credit',
    permission: 'credits.create',
    steps: [
      {
        anchor: TOUR_ANCHORS.quickActions,
        i18nKey: 'onboarding.tour.credit.steps.quickActions',
      },
      {
        anchor: TOUR_ANCHORS.quickActionsCredit,
        i18nKey: 'onboarding.tour.credit.steps.button',
      },
      {
        anchor: TOUR_ANCHORS.ageingBuckets,
        i18nKey: 'onboarding.tour.credit.steps.buckets',
      },
      {
        anchor: TOUR_ANCHORS.navTransactions,
        i18nKey: 'onboarding.tour.credit.steps.transactions',
      },
      {
        anchor: TOUR_ANCHORS.navCredit,
        i18nKey: 'onboarding.tour.credit.steps.nav',
      },
    ],
  },
  {
    id: 'settle',
    i18nKey: 'onboarding.tour.settle',
    permission: 'settlements.create',
    steps: [
      {
        anchor: TOUR_ANCHORS.quickActions,
        i18nKey: 'onboarding.tour.settle.steps.quickActions',
      },
      {
        anchor: TOUR_ANCHORS.totalPayable,
        i18nKey: 'onboarding.tour.settle.steps.totalPayable',
      },
      {
        anchor: TOUR_ANCHORS.overdueBucket,
        i18nKey: 'onboarding.tour.settle.steps.overdue',
      },
      {
        anchor: TOUR_ANCHORS.quickActionsSettle,
        i18nKey: 'onboarding.tour.settle.steps.button',
      },
      {
        anchor: TOUR_ANCHORS.wallet,
        i18nKey: 'onboarding.tour.settle.steps.wallet',
      },
      {
        anchor: TOUR_ANCHORS.navSettlements,
        i18nKey: 'onboarding.tour.settle.steps.nav',
      },
    ],
  },
];

export function tourById(id: TourId): TourDefinition | undefined {
  return TOURS.find((tour) => tour.id === id);
}
