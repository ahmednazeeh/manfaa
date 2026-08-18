import {
  isCustomerStatusReasonKey,
  type ClaimState,
  type CustomerStatusReasonKey,
  type CustomerTransactionStatus,
} from '@manfaa/api-client';
import type { TFunction } from 'i18next';

/**
 * Human labels for every machine code the customer app can render: the §6
 * customer-facing status, the `status_reason` key behind it, and claim
 * states. PLAN §13b task #22 — no raw snake_case anywhere in a UI, least of
 * all the one a shopper reads.
 *
 * Each map is an EXHAUSTIVE `Record<Union, string>` over the union the API
 * publishes (@manfaa/api-client), so a new state or reason code on the
 * contract fails this file's typecheck until it has words. Values are
 * i18next KEYS — locales/en.json and locales/dv.json carry the wording, and
 * the repo-root scripts/labels-audit.mjs asserts that every key below has
 * words in BOTH.
 *
 * `status_reason` is the interesting one: for a pending or written-off row
 * the API sends a key derived from the STATE (validation_window,
 * merchant_settlement_window, under_review, merchant_not_settled), but a
 * REVERSED row echoes its stored reason_code instead — so the customer app
 * must be able to say every §6 reason code too, in shopper language rather
 * than accounting language ("This purchase was refunded", not
 * `customer_refund`).
 *
 * Several reason lines interpolate {{merchant}}; every caller passes it.
 */

const STATUS_KEYS: Record<CustomerTransactionStatus, string> = {
  pending: 'status.pending',
  confirmed: 'status.confirmed',
  paid: 'status.paid',
  reversed: 'status.reversed',
  unpaid: 'status.unpaid',
};

const REASON_KEYS: Record<CustomerStatusReasonKey, string> = {
  // Derived from the §6 state (CustomerFacingStatus::reasonKey).
  validation_window: 'reasons.validation_window',
  merchant_settlement_window: 'reasons.merchant_settlement_window',
  under_review: 'reasons.under_review',
  merchant_not_settled: 'reasons.merchant_not_settled',
  reversed: 'reasons.reversed',
  // Echoed from the row's stored reason_code on a reversed transaction.
  auto_validation_window: 'reasons.validation_window',
  backdated_final: 'reasons.backdated_final',
  below_minimum: 'reasons.below_minimum',
  merchant_suspended: 'reasons.merchant_suspended',
  merchant_unpublished: 'reasons.merchant_unpublished',
  settlement_allocated: 'reasons.settlement_allocated',
  payout_completed: 'reasons.payout_completed',
  merchant_default_90d: 'reasons.merchant_not_settled',
  claim_approved: 'reasons.claim_approved',
  customer_refund: 'reasons.customer_refund',
  till_void: 'reasons.till_void',
  duplicate: 'reasons.duplicate',
  other: 'reasons.other',
  admin_release: 'reasons.admin_release',
  admin_reject: 'reasons.admin_reject',
  stale_timestamp: 'reasons.stale_timestamp',
  admin_release_stale: 'reasons.admin_release',
};

/**
 * The safety net for a `status_reason` this build has never heard of — an
 * older row, or an API deploy that lands before the app. Neutral prose; the
 * one thing it must never do is print the key.
 */
const UNKNOWN_REASON_KEY = 'reasons.fallback';

const CLAIM_STATE_KEYS: Record<ClaimState, string> = {
  open: 'claims.state.open',
  in_review: 'claims.state.in_review',
  approved: 'claims.state.approved',
  rejected: 'claims.state.rejected',
};

export function transactionStatusLabel(
  t: TFunction,
  status: CustomerTransactionStatus,
): string {
  return t(STATUS_KEYS[status]);
}

/**
 * The one-line explanation under a transaction. Null when the API sends no
 * reason (a settled reward needs no qualifier).
 */
export function statusReasonLabel(
  t: TFunction,
  reason: string | null | undefined,
  merchant: string,
): string | null {
  if (reason === null || reason === undefined || reason === '') {
    return null;
  }
  const key = isCustomerStatusReasonKey(reason)
    ? REASON_KEYS[reason]
    : UNKNOWN_REASON_KEY;
  return t(key, { merchant });
}

export function claimStateLabel(t: TFunction, state: ClaimState): string {
  return t(CLAIM_STATE_KEYS[state]);
}
