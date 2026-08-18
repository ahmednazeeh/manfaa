import {
  isTransactionReasonCode,
  type AdminRole,
  type ChangeRequestKind,
  type ChangeRequestStatus,
  type ClaimState,
  type CustomerStatus,
  type MerchantChannel,
  type MerchantNoticeType,
  type MerchantStatus,
  type PayoutBatchState,
  type PayoutItemState,
  type PromptDiscountReason,
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
  merchant_unpublished: 'Store paused',
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

const CUSTOMER_STATUSES: Record<CustomerStatus, string> = {
  active: 'Active',
  suspended: 'Disabled',
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
 * Why a batch did — or did not — get the PLAN §1 prompt-payment discount.
 * Written as a clause that finishes "Prompt-payment discount —", because the
 * question an admin is asking at the matching screen is always the same one:
 * why is the amount due lower (or not lower) than the lines add up to?
 */
const PROMPT_DISCOUNT_REASONS: Record<PromptDiscountReason, string> = {
  eligible:
    'granted: the batch covers every transaction this merchant had outstanding, and every line was still inside the age window at submit.',
  not_all_outstanding:
    'not granted: the merchant still has payable transactions this batch does not cover, including any frozen on an earlier unpaid batch.',
  line_too_old:
    'not granted: at least one line had already reached the age window when the batch was submitted.',
  clock_not_started:
    'not granted: at least one line has no settlement clock (a null clock_start_at), so its age cannot be established — fix the transaction before the merchant can earn this.',
  disabled:
    'not granted: the incentive is switched off platform-wide (rate 0%).',
};

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

/**
 * MR9 store-change review queue. The four things a live store can ask to
 * change, and the four states the request can be in. Sentence-case here and
 * capitalised where a chip renders it — the server's own `kind_label` is
 * lower-case prose written for the merchant's notification, not for a table
 * column, which is why the console keeps its own words.
 */
const CHANGE_KINDS: Record<ChangeRequestKind, string> = {
  profile: 'Store profile',
  branch_create: 'New branch',
  branch_update: 'Branch update',
  branch_delete: 'Branch removal',
};

const CHANGE_REQUEST_STATUSES: Record<ChangeRequestStatus, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  superseded: 'Superseded',
};

/**
 * What a changed field is called in front of a reviewer. Keyed by the wire
 * name; `name` deliberately reads differently on a branch request, where it
 * is an address's name and not the shop's.
 *
 * A free `Record<string, string>` rather than an exhaustive one: the payload
 * is JSON whose keys follow whatever the merchant endpoints validate, so an
 * unlisted key degrades to a de-snake_cased word instead of failing a build
 * that cannot know the server's next field.
 */
const CHANGE_FIELDS: Record<string, string> = {
  name: 'Store name',
  name_dv: 'Dhivehi name',
  category: 'Category',
  channel: 'Channel',
  description: 'Store description',
  eligibility_basis: 'Terms & exclusions',
  website_url: 'Website',
  logo: 'Logo',
  address: 'Address',
  lat: 'Latitude',
  lng: 'Longitude',
};

const BRANCH_CHANGE_FIELDS: Record<string, string> = {
  name: 'Branch name',
};

export function changeKindLabel(kind: ChangeRequestKind): string {
  return CHANGE_KINDS[kind];
}

export function changeRequestStatusLabel(status: ChangeRequestStatus): string {
  return CHANGE_REQUEST_STATUSES[status];
}

/** "eligibility_basis" -> "Terms & exclusions"; "some_new_key" -> "Some new key". */
export function changeFieldLabel(
  kind: ChangeRequestKind,
  field: string,
): string {
  if (kind !== 'profile' && field in BRANCH_CHANGE_FIELDS) {
    return BRANCH_CHANGE_FIELDS[field]!;
  }
  if (field in CHANGE_FIELDS) {
    return CHANGE_FIELDS[field]!;
  }
  const words = field.replace(/_/g, ' ');
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * A refusal from the decision endpoints, in words. The server sends its own
 * `message` too — these exist so the console can add what the admin should
 * DO about a code, which the API has no business knowing.
 */
const CHANGE_REQUEST_REFUSALS: Record<string, string> = {
  change_not_pending:
    'This request was already decided — most likely in another tab, or the merchant replaced it with a newer one.',
  branch_missing:
    'The branch this request targets no longer exists, so there is nothing to change.',
  branch_referenced:
    'This branch is referenced by transactions or promotions, so it can never be deleted. Refuse the request and tell the store to stop using the branch instead.',
};

/** Null when the refusal carries no code this console has advice for. */
export function changeRequestRefusalLabel(
  code: string | null | undefined,
): string | null {
  return code != null && code in CHANGE_REQUEST_REFUSALS
    ? CHANGE_REQUEST_REFUSALS[code]!
    : null;
}

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

/** `suspended` reads as "Disabled" — the admin lever is enable/disable. */
export function customerStatusLabel(status: CustomerStatus): string {
  return CUSTOMER_STATUSES[status];
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

/**
 * The prompt-payment discount decision in words. The API sets a reason on
 * refusals as well as grants, which is what lets the queue explain a full
 * price as readily as a discounted one.
 */
export function promptDiscountReasonLabel(
  reason: PromptDiscountReason,
): string {
  return PROMPT_DISCOUNT_REASONS[reason];
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
