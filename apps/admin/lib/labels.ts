import {
  isTransactionReasonCode,
  type AdminRole,
  type ClaimState,
  type MerchantChannel,
  type MerchantNoticeType,
  type MerchantStatus,
  type PayoutBatchState,
  type PayoutItemState,
  type SettlementFundingMethod,
  type SettlementPaymentState,
  type SettlementState,
  type TransactionOrigin,
  type TransactionReasonCode,
  type TransactionState,
} from '@manfaa/api-client';

/**
 * Human labels for every machine code the admin console can render — the §6
 * state machines, transaction origins, the reason_code qualifier, merchant
 * lifecycle and claim states. PLAN §13b task #22: no raw snake_case in any
 * UI, the hold queue included.
 *
 * Every map is an EXHAUSTIVE `Record<Union, string>` over the union the API
 * publishes (@manfaa/api-client), so adding a state or a reason code to the
 * contract fails this file's typecheck until someone writes the words. That
 * is the whole point: a leak becomes a build error rather than a snake_case
 * string in front of an operator deciding whether to release a hold.
 *
 * Unlike apps/web and apps/merchant these are English prose, not i18n keys.
 * The admin console is an internal, English-only surface — it has no i18n
 * runtime, no locale files and no language switcher, and every other string
 * in it is a literal. Giving this one module a translation layer nothing
 * else in the app has would be pretend localisation. If the console is ever
 * localised, this file is the single place its state vocabulary lives.
 */

const TRANSACTION_STATES: Record<TransactionState, string> = {
  tracked: 'Tracked',
  awaiting_validation: 'Awaiting validation',
  payable_unfunded: 'Payable (unfunded)',
  on_hold: 'On hold',
  confirmed: 'Confirmed',
  paid: 'Paid',
  reversed: 'Reversed',
  written_off: 'Written off',
};

const TRANSACTION_ORIGINS: Record<TransactionOrigin, string> = {
  pos: 'Till (POS)',
  manual: 'Merchant panel',
  online_link: 'Online checkout',
  api_phone: 'Phone lookup (API)',
  card_linked: 'Linked card',
  claim: 'Approved claim',
};

/**
 * Why the row sits where it does. Wording is the operator's, not the
 * ledger's — "Paid by store", not `settlement_allocated`.
 */
const TRANSACTION_REASONS: Record<TransactionReasonCode, string> = {
  auto_validation_window: 'Validated automatically',
  backdated_final: 'Backdated — cannot be reversed',
  below_minimum: 'Below minimum sale',
  merchant_suspended: 'Store suspended',
  settlement_allocated: 'Paid by store',
  payout_completed: 'Paid out',
  merchant_default_90d: 'Written off — store never paid',
  claim_approved: 'From an approved claim',
  customer_refund: 'Refunded',
  till_void: 'Voided at the till',
  duplicate: 'Duplicate sale',
  other: 'Corrected — other reason',
  admin_release: 'Released by Manfaa',
  admin_reject: 'Rejected by Manfaa',
  stale_timestamp: 'Held — sale was backdated (legacy)',
  admin_release_stale: 'Released after backdated review (legacy)',
};

/**
 * The safety net for a reason_code this build has never heard of — an older
 * production row, or an API deploy that lands before the console. Neutral
 * prose; the one thing it must never do is print the code.
 */
const UNKNOWN_REASON = 'Set by Manfaa';

const SETTLEMENT_STATES: Record<SettlementState, string> = {
  draft: 'Draft',
  awaiting_payment: 'Awaiting payment',
  payment_review: 'Payment review',
  settled: 'Settled',
  partially_settled: 'Partially settled',
  cancelled: 'Cancelled',
};

const SETTLEMENT_PAYMENT_STATES: Record<SettlementPaymentState, string> = {
  pending: 'Pending match',
  matched: 'Matched',
  rejected: 'Rejected',
};

const PAYOUT_BATCH_STATES: Record<PayoutBatchState, string> = {
  draft: 'Draft',
  approved: 'Approved',
  processing: 'Processing',
  sent: 'Sent',
  completed: 'Completed',
  partially_failed: 'Partially failed',
  cancelled: 'Cancelled',
};

const PAYOUT_ITEM_STATES: Record<PayoutItemState, string> = {
  pending: 'Pending',
  sent: 'Sent',
  paid: 'Paid',
  failed: 'Failed',
};

const MERCHANT_STATUSES: Record<MerchantStatus, string> = {
  draft: 'Draft',
  pending_review: 'Pending review',
  rejected: 'Rejected',
  active: 'Active',
  suspended: 'Suspended',
  closed: 'Closed',
};

const CLAIM_STATES: Record<ClaimState, string> = {
  open: 'Open',
  in_review: 'In review',
  approved: 'Approved',
  rejected: 'Rejected',
};

/**
 * How the money moved. The same two words cover a settlement's
 * `funding_method` and a payment row's `method` — the wallet path runs the
 * identical §7 code path, only the source differs.
 */
const FUNDING_METHODS: Record<SettlementFundingMethod, string> = {
  bank: 'Bank transfer',
  wallet: 'Merchant wallet',
};

const UNKNOWN_FUNDING_METHOD = 'Other';

/**
 * Where the store sells. The literal enum value — and the word "both" above
 * all — never reaches the screen (§1 decision 2026-08-15).
 */
const MERCHANT_CHANNELS: Record<MerchantChannel, string> = {
  in_store: 'In Store',
  online: 'Online',
  both: 'In Store & Online',
};

/** The §7 escalation ladder, as an operator reads it. */
const NOTICE_TYPES: Record<MerchantNoticeType, string> = {
  reminder_day10: 'Day 10 reminder',
  urgent_day13: 'Day 13 urgent',
  due_day15: 'Day 15 due',
  suspended: 'Suspended',
  reinstated: 'Reinstated',
  write_off: 'Write-off',
};

/**
 * How a notice was delivered. Phase 1 records `log` only (NoticeRecorder);
 * SMS and email join the list as those senders start writing notices, and
 * the column stays a free string until they do.
 */
const NOTICE_CHANNELS: Record<string, string> = {
  log: 'Recorded in the log',
  sms: 'SMS',
  email: 'Email',
};

const UNKNOWN_NOTICE_CHANNEL = 'Other channel';

const ADMIN_ROLES: Record<AdminRole, string> = {
  admin: 'Admin',
  superadmin: 'Superadmin',
};

const UNKNOWN_ADMIN_ROLE = 'Staff';

export function transactionStateLabel(state: TransactionState): string {
  return TRANSACTION_STATES[state];
}

export function transactionOriginLabel(origin: TransactionOrigin): string {
  return TRANSACTION_ORIGINS[origin];
}

/**
 * The row's state qualifier in words. `reason_code` is a nullable free string
 * on the wire (an older row can carry a code this build predates), so an
 * unrecognised value falls back to neutral prose rather than printing itself.
 * Returns null when there is no qualifier — a clean pending sale has none.
 */
export function reasonCodeLabel(
  code: string | null | undefined,
): string | null {
  if (code === null || code === undefined || code === '') {
    return null;
  }
  return isTransactionReasonCode(code)
    ? TRANSACTION_REASONS[code]
    : UNKNOWN_REASON;
}

export function settlementStateLabel(state: SettlementState): string {
  return SETTLEMENT_STATES[state];
}

export function settlementPaymentStateLabel(
  state: SettlementPaymentState,
): string {
  return SETTLEMENT_PAYMENT_STATES[state];
}

export function payoutBatchStateLabel(state: PayoutBatchState): string {
  return PAYOUT_BATCH_STATES[state];
}

export function payoutItemStateLabel(state: PayoutItemState): string {
  return PAYOUT_ITEM_STATES[state];
}

export function merchantStatusLabel(status: MerchantStatus): string {
  return MERCHANT_STATUSES[status];
}

export function claimStateLabel(state: ClaimState): string {
  return CLAIM_STATES[state];
}

/**
 * A settlement's funding_method, or a payment row's `method` — the latter is
 * a free string on the wire, so anything unrecognised falls back to prose
 * rather than printing itself.
 */
export function fundingMethodLabel(method: string): string {
  return method in FUNDING_METHODS
    ? FUNDING_METHODS[method as SettlementFundingMethod]
    : UNKNOWN_FUNDING_METHOD;
}

export function merchantChannelLabel(channel: MerchantChannel): string {
  return MERCHANT_CHANNELS[channel];
}

export function noticeTypeLabel(type: MerchantNoticeType): string {
  return NOTICE_TYPES[type];
}

/** Free string on the wire, so anything unlisted degrades to prose. */
export function noticeChannelLabel(channel: string): string {
  return NOTICE_CHANNELS[channel] ?? UNKNOWN_NOTICE_CHANNEL;
}

/**
 * The signed-in admin's role. `AdminUserSchema` types it as a plain string
 * (the session payload is parsed before AdminRole existed), so an unlisted
 * value degrades to prose rather than printing itself.
 */
export function adminRoleLabel(role: string): string {
  return role in ADMIN_ROLES
    ? ADMIN_ROLES[role as AdminRole]
    : UNKNOWN_ADMIN_ROLE;
}
