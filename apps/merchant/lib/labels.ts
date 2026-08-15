import {
  isTransactionReasonCode,
  isVendorAbility,
  isWalletMovementType,
  type MerchantChannel,
  type MerchantStaffRole,
  type MerchantStatus,
  type PromotionStatus,
  type PromptDiscountReason,
  type SettlementFundingMethod,
  type SettlementPaymentState,
  type SettlementState,
  type TransactionOrigin,
  type TransactionReasonCode,
  type TransactionState,
  type VendorAbility,
  type WalletMovementType,
} from '@manfaa/api-client';
import type { TFunction } from 'i18next';

/**
 * Human labels for every machine code the merchant panel can render — §6
 * states, transaction origins and the reason_code qualifier (PLAN §13b task
 * #22: no raw snake_case in any UI).
 *
 * Each map is an EXHAUSTIVE `Record<Union, string>` keyed by the union the
 * API publishes (@manfaa/api-client), so adding a state, an origin or a
 * reason code to the contract fails this file's typecheck until somebody
 * writes the words a shopkeeper reads. The values are i18next KEYS, never
 * prose — locales/en.json and locales/dv.json carry the wording, and
 * the repo-root scripts/labels-audit.mjs asserts that every key below has
 * words in BOTH.
 *
 * The `t` argument is passed in rather than pulled from a hook so these work
 * in server components, event handlers and toasts as well as in render.
 */

const STATE_KEYS: Record<TransactionState, string> = {
  tracked: 'labels.state.tracked',
  awaiting_validation: 'labels.state.awaiting_validation',
  payable_unfunded: 'labels.state.payable_unfunded',
  on_hold: 'labels.state.on_hold',
  confirmed: 'labels.state.confirmed',
  paid: 'labels.state.paid',
  reversed: 'labels.state.reversed',
  written_off: 'labels.state.written_off',
};

const ORIGIN_KEYS: Record<TransactionOrigin, string> = {
  pos: 'labels.origin.pos',
  manual: 'labels.origin.manual',
  online_link: 'labels.origin.online_link',
  api_phone: 'labels.origin.api_phone',
  card_linked: 'labels.origin.card_linked',
  claim: 'labels.origin.claim',
};

const REASON_KEYS: Record<TransactionReasonCode, string> = {
  auto_validation_window: 'labels.reason.auto_validation_window',
  backdated_final: 'labels.reason.backdated_final',
  below_minimum: 'labels.reason.below_minimum',
  merchant_suspended: 'labels.reason.merchant_suspended',
  settlement_allocated: 'labels.reason.settlement_allocated',
  payout_completed: 'labels.reason.payout_completed',
  merchant_default_90d: 'labels.reason.merchant_default_90d',
  claim_approved: 'labels.reason.claim_approved',
  customer_refund: 'labels.reason.customer_refund',
  till_void: 'labels.reason.till_void',
  duplicate: 'labels.reason.duplicate',
  other: 'labels.reason.other',
  admin_release: 'labels.reason.admin_release',
  admin_reject: 'labels.reason.admin_reject',
  stale_timestamp: 'labels.reason.stale_timestamp',
  admin_release_stale: 'labels.reason.admin_release_stale',
};

/**
 * The safety net for a reason_code this build has never heard of (an older
 * production row, or an API deploy that lands before the panel). Neutral
 * prose — the one thing it must never do is print the code.
 */
const UNKNOWN_REASON_KEY = 'labels.reason.unknown';

const SETTLEMENT_STATE_KEYS: Record<SettlementState, string> = {
  draft: 'labels.settlementState.draft',
  awaiting_payment: 'labels.settlementState.awaiting_payment',
  payment_review: 'labels.settlementState.payment_review',
  settled: 'labels.settlementState.settled',
  partially_settled: 'labels.settlementState.partially_settled',
  cancelled: 'labels.settlementState.cancelled',
};

/**
 * Receipt-review states reuse the settlement namespace's existing wording
 * rather than restating it — one translation per sentence, and the map here
 * is what makes the set exhaustive.
 */
const PAYMENT_STATE_KEYS: Record<SettlementPaymentState, string> = {
  pending: 'settlement.paymentPending',
  matched: 'settlement.paymentMatched',
  rejected: 'settlement.paymentRejected',
};

const PROMOTION_STATUS_KEYS: Record<PromotionStatus, string> = {
  draft: 'labels.promotionStatus.draft',
  published: 'labels.promotionStatus.published',
  ended: 'labels.promotionStatus.ended',
  cancelled: 'labels.promotionStatus.cancelled',
};

/**
 * How the money moved. The same two words cover a settlement's
 * funding_method and a payment row's `method` — the wallet path runs the
 * identical §7 code, only the source differs.
 */
const FUNDING_METHOD_KEYS: Record<SettlementFundingMethod, string> = {
  bank: 'labels.fundingMethod.bank',
  wallet: 'labels.fundingMethod.wallet',
};

const UNKNOWN_FUNDING_METHOD_KEY = 'labels.fundingMethod.unknown';

/**
 * The store's own lifecycle and shopfront vocabulary. These were dynamic
 * `t()` keys built from the value — correct today only because the locale
 * files happened to be complete. Pinned to the unions here so they stay that
 * way by compiler, not by luck. `channel` never displays "both" (§1): the
 * translation reads "In Store & Online".
 */
const MERCHANT_STATUS_KEYS: Record<MerchantStatus, string> = {
  draft: 'settings.status.draft',
  pending_review: 'settings.status.pending_review',
  rejected: 'settings.status.rejected',
  active: 'settings.status.active',
  suspended: 'settings.status.suspended',
  closed: 'settings.status.closed',
};

const MERCHANT_CHANNEL_KEYS: Record<MerchantChannel, string> = {
  in_store: 'channel.in_store',
  online: 'channel.online',
  both: 'channel.both',
};

const MERCHANT_CHANNEL_HINT_KEYS: Record<MerchantChannel, string> = {
  in_store: 'channelHint.in_store',
  online: 'channelHint.online',
  both: 'channelHint.both',
};

const MERCHANT_ROLE_KEYS: Record<MerchantStaffRole, string> = {
  owner: 'roles.owner',
  manager: 'roles.manager',
  staff: 'roles.staff',
};

const MERCHANT_ROLE_HINT_KEYS: Record<MerchantStaffRole, string> = {
  owner: 'roles.ownerHint',
  manager: 'roles.managerHint',
  staff: 'roles.staffHint',
};

/**
 * The four vendor-token abilities (PLAN §9.1) in PLAIN LANGUAGE. The wire
 * values are developer strings — `transactions:write` means nothing to a
 * shopkeeper deciding what to hand a POS company — so the wizard shows
 * these words and the codes appear nowhere in the UI.
 */
const VENDOR_ABILITY_KEYS: Record<VendorAbility, string> = {
  'transactions:write': 'apiAccess.abilities.transactionsWrite.label',
  'transactions:reverse': 'apiAccess.abilities.transactionsReverse.label',
  'rates:read': 'apiAccess.abilities.ratesRead.label',
  'customers:lookup': 'apiAccess.abilities.customersLookup.label',
};

const VENDOR_ABILITY_HINT_KEYS: Record<VendorAbility, string> = {
  'transactions:write': 'apiAccess.abilities.transactionsWrite.hint',
  'transactions:reverse': 'apiAccess.abilities.transactionsReverse.hint',
  'rates:read': 'apiAccess.abilities.ratesRead.hint',
  'customers:lookup': 'apiAccess.abilities.customersLookup.hint',
};

/** What moved the store's wallet balance. */
const WALLET_MOVEMENT_KEYS: Record<WalletMovementType, string> = {
  top_up: 'labels.walletMovement.top_up',
  settlement: 'labels.walletMovement.settlement',
  settlement_credit: 'labels.walletMovement.settlement_credit',
};

const UNKNOWN_WALLET_MOVEMENT_KEY = 'labels.walletMovement.unknown';

/**
 * Why the batch in front of the merchant did — or did not — earn the PLAN §1
 * prompt-payment discount. The API answers with a machine code on refusals as
 * well as grants, which is the whole point: "no discount" is an answer the
 * merchant is entitled to understand, and every one of these has an action
 * behind it (settle the rest too / you left it too long).
 */
const PROMPT_DISCOUNT_REASON_KEYS: Record<PromptDiscountReason, string> = {
  eligible: 'settlement.discountReasonEligible',
  not_all_outstanding: 'settlement.discountReasonNotAllOutstanding',
  line_too_old: 'settlement.discountReasonLineTooOld',
  clock_not_started: 'settlement.discountReasonClockNotStarted',
  disabled: 'settlement.discountReasonDisabled',
};

/**
 * Not a status the API stores — `published` plus a window that covers now
 * (the is_live flag). It gets a label of its own because that is the word the
 * merchant actually needs on the chip.
 */
const PROMOTION_LIVE_KEY = 'labels.promotionStatus.live';

export function transactionStateLabel(
  t: TFunction,
  state: TransactionState,
): string {
  return t(STATE_KEYS[state]);
}

export function transactionOriginLabel(
  t: TFunction,
  origin: TransactionOrigin,
): string {
  return t(ORIGIN_KEYS[origin]);
}

/**
 * The row's state qualifier in words. `reason_code` is a nullable free string
 * on the wire (an older row can carry a code this build predates), so an
 * unrecognised value falls back to neutral prose — the one thing it must
 * never do is print itself.
 */
export function reasonCodeLabel(
  t: TFunction,
  code: string | null | undefined,
): string | null {
  if (code === null || code === undefined || code === '') {
    return null;
  }
  return isTransactionReasonCode(code)
    ? t(REASON_KEYS[code])
    : t(UNKNOWN_REASON_KEY);
}

export function settlementStateLabel(
  t: TFunction,
  state: SettlementState,
): string {
  return t(SETTLEMENT_STATE_KEYS[state]);
}

export function settlementPaymentStateLabel(
  t: TFunction,
  state: SettlementPaymentState,
): string {
  return t(PAYMENT_STATE_KEYS[state]);
}

export function promotionStatusLabel(
  t: TFunction,
  status: PromotionStatus,
  live = false,
): string {
  return live ? t(PROMOTION_LIVE_KEY) : t(PROMOTION_STATUS_KEYS[status]);
}

/**
 * A settlement's funding_method, or a payment row's `method` — the latter is
 * a free string on the wire, so anything unrecognised falls back to prose
 * rather than printing itself.
 */
export function fundingMethodLabel(t: TFunction, method: string): string {
  return method in FUNDING_METHOD_KEYS
    ? t(FUNDING_METHOD_KEYS[method as SettlementFundingMethod])
    : t(UNKNOWN_FUNDING_METHOD_KEY);
}

/**
 * A wallet movement's type. Free string on the wire, so an unrecognised
 * value falls back to prose rather than printing itself.
 */
export function walletMovementLabel(t: TFunction, type: string): string {
  return isWalletMovementType(type)
    ? t(WALLET_MOVEMENT_KEYS[type])
    : t(UNKNOWN_WALLET_MOVEMENT_KEY);
}

/**
 * The discount verdict in plain language. `rate` is the platform's configured
 * rate already formatted ("5%") and `days` its age window — both come from
 * the API's own evaluation, never from anything the panel worked out, so the
 * sentence can never promise a rate the server is not applying.
 */
export function promptDiscountReasonLabel(
  t: TFunction,
  reason: PromptDiscountReason,
  values: { rate: string; days: number },
): string {
  return t(PROMPT_DISCOUNT_REASON_KEYS[reason], values);
}

export function merchantStatusLabel(
  t: TFunction,
  status: MerchantStatus,
): string {
  return t(MERCHANT_STATUS_KEYS[status]);
}

export function merchantChannelLabel(
  t: TFunction,
  channel: MerchantChannel,
): string {
  return t(MERCHANT_CHANNEL_KEYS[channel]);
}

export function merchantChannelHint(
  t: TFunction,
  channel: MerchantChannel,
): string {
  return t(MERCHANT_CHANNEL_HINT_KEYS[channel]);
}

export function merchantRoleLabel(
  t: TFunction,
  role: MerchantStaffRole,
): string {
  return t(MERCHANT_ROLE_KEYS[role]);
}

export function merchantRoleHint(
  t: TFunction,
  role: MerchantStaffRole,
): string {
  return t(MERCHANT_ROLE_HINT_KEYS[role]);
}

/**
 * A stored credential's abilities are plain strings — a row issued by an
 * older build may name an ability this one predates — so an unrecognised
 * value degrades to neutral prose rather than printing itself.
 */
export function vendorAbilityLabel(t: TFunction, ability: string): string {
  return isVendorAbility(ability)
    ? t(VENDOR_ABILITY_KEYS[ability])
    : t('apiAccess.abilities.unknown');
}

export function vendorAbilityHint(t: TFunction, ability: VendorAbility): string {
  return t(VENDOR_ABILITY_HINT_KEYS[ability]);
}

/**
 * The four things the setup wizard can still be missing at submit
 * (`setup_incomplete`, api-client onboarding). Local to the wizard rather
 * than an API union, but pinned here for the same reason as the rest: the
 * key is never built from the value.
 */
export const SETUP_MISSING_KEYS = [
  'category',
  'channel',
  'rate',
  'terms',
] as const;
export type SetupMissingKey = (typeof SETUP_MISSING_KEYS)[number];

const SETUP_MISSING_LABEL_KEYS: Record<SetupMissingKey, string> = {
  category: 'setup.missing.category',
  channel: 'setup.missing.channel',
  rate: 'setup.missing.rate',
  terms: 'setup.missing.terms',
};

export function setupMissingLabel(t: TFunction, key: SetupMissingKey): string {
  return t(SETUP_MISSING_LABEL_KEYS[key]);
}
