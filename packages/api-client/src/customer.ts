import { z } from 'zod';
import { apiFetch } from './client';
import {
  BankSlugSchema,
  ClaimStateSchema,
  dataWrapped,
  paginated,
  TRANSACTION_REASON_CODES,
  type TransactionReasonCode,
} from './resources';

/**
 * Typed contracts for the customer surface (Phase 3): password login, the
 * phone-OTP signup flow, the balance screen, earning history with the §6
 * customer-facing status mapping, the payout account, and missing-transaction
 * claims. All amounts sent and received are integer laari.
 */

interface RequestOptions {
  signal?: AbortSignal;
}

function queryString(
  params: Record<string, string | number | undefined>,
): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) {
      search.set(key, String(value));
    }
  }
  const encoded = search.toString();
  return encoded === '' ? '' : `?${encoded}`;
}

// ---------------------------------------------------------------------------
// The customer identity
// ---------------------------------------------------------------------------

export const CustomerSchema = z.object({
  id: z.number().int(),
  /** The 6-digit code quoted at the till. */
  customer_code: z.string(),
  name: z.string(),
  /** Masked by the API: dialling prefix and last three digits only. */
  phone: z.string(),
  status: z.string(),
  kyc_status: z.string(),
  /** Profile picture capability URL, null when none is set. */
  avatar_url: z.string().nullish().default(null),
});
export type Customer = z.infer<typeof CustomerSchema>;

export const CustomerResponseSchema = dataWrapped(CustomerSchema);
export type CustomerResponse = z.infer<typeof CustomerResponseSchema>;

// ---------------------------------------------------------------------------
// Password auth (existing accounts)
// ---------------------------------------------------------------------------

export const CustomerLoginRequestSchema = z.object({
  phone: z.string().min(1),
  password: z.string().min(1),
});
export type CustomerLoginRequest = z.infer<typeof CustomerLoginRequestSchema>;

/** POST /api/customer/auth/login — call bootstrapCsrf() once beforehand. */
export function customerLogin(
  body: CustomerLoginRequest,
  options: RequestOptions = {},
): Promise<CustomerResponse> {
  return apiFetch('/api/customer/auth/login', CustomerResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

/** POST /api/customer/auth/logout — 204. */
export async function customerLogout(
  options: RequestOptions = {},
): Promise<void> {
  await apiFetch('/api/customer/auth/logout', z.undefined(), {
    method: 'POST',
    signal: options.signal,
  });
}

/** GET /api/customer/auth/me — the authenticated customer. */
export function getCustomerMe(
  options: RequestOptions = {},
): Promise<CustomerResponse> {
  return apiFetch('/api/customer/auth/me', CustomerResponseSchema, {
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Profile picture
// ---------------------------------------------------------------------------

export const AvatarResponseSchema = dataWrapped(
  z.object({ avatar_url: z.string().nullable() }),
);
export type AvatarResponse = z.infer<typeof AvatarResponseSchema>;

/** POST /api/customer/avatar — multipart upload; replaces any existing one. */
export function uploadCustomerAvatar(
  file: File,
  options: RequestOptions = {},
): Promise<AvatarResponse> {
  const body = new FormData();
  body.append('avatar', file);

  return apiFetch('/api/customer/avatar', AvatarResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

/** DELETE /api/customer/avatar — removes the picture; idempotent. */
export function removeCustomerAvatar(
  options: RequestOptions = {},
): Promise<AvatarResponse> {
  return apiFetch('/api/customer/avatar', AvatarResponseSchema, {
    method: 'DELETE',
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// OTP signup flow: request-otp -> verify-otp -> register
// ---------------------------------------------------------------------------

/** Maldives mobile numbers: +960 then a 7-digit number starting 7 or 9. */
export const CustomerPhoneSchema = z.string().regex(/^\+960[79]\d{6}$/);

export const RequestOtpRequestSchema = z.object({
  phone: CustomerPhoneSchema,
});
export type RequestOtpRequest = z.infer<typeof RequestOtpRequestSchema>;

export const RequestOtpResponseSchema = z.object({
  /**
   * Deliberately identical whether or not the phone has an account —
   * enumeration-safe. Throttled 3/hour per phone, 10/hour per IP; a 429
   * arrives as an ApiError with a Retry-After header.
   */
  message: z.string(),
});
export type RequestOtpResponse = z.infer<typeof RequestOtpResponseSchema>;

/** POST /api/customer/auth/request-otp — sends the SMS code. */
export function requestCustomerOtp(
  body: RequestOtpRequest,
  options: RequestOptions = {},
): Promise<RequestOtpResponse> {
  return apiFetch('/api/customer/auth/request-otp', RequestOtpResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

export const VerifyOtpRequestSchema = z.object({
  phone: CustomerPhoneSchema,
  /** The 6-digit SMS code. */
  code: z.string().regex(/^\d{6}$/),
});
export type VerifyOtpRequest = z.infer<typeof VerifyOtpRequestSchema>;

export const VerifyOtpResponseSchema = dataWrapped(
  z.object({
    /** Short-lived proof of phone possession; spend it on register. */
    signup_token: z.string(),
    expires_in_minutes: z.number().int(),
  }),
);
export type VerifyOtpResponse = z.infer<typeof VerifyOtpResponseSchema>;

/**
 * POST /api/customer/auth/verify-otp — exchanges a correct code for a signup
 * token. Failures are 422 ValidationExceptions keyed on `code`:
 * `otp_invalid` or `otp_attempts_exceeded` (keys, not prose — translate).
 */
export function verifyCustomerOtp(
  body: VerifyOtpRequest,
  options: RequestOptions = {},
): Promise<VerifyOtpResponse> {
  return apiFetch('/api/customer/auth/verify-otp', VerifyOtpResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

export const RegisterCustomerRequestSchema = z.object({
  signup_token: z.string().min(1),
  name: z.string().min(1).max(120),
  password: z.string().min(8).max(255),
});
export type RegisterCustomerRequest = z.infer<
  typeof RegisterCustomerRequestSchema
>;

/**
 * POST /api/customer/auth/register — creates the account (201) and logs the
 * session in. 422 keys: `signup_token_invalid`, `phone_already_registered`.
 */
export function registerCustomer(
  body: RegisterCustomerRequest,
  options: RequestOptions = {},
): Promise<CustomerResponse> {
  return apiFetch('/api/customer/auth/register', CustomerResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// GET /api/customer/balance
// ---------------------------------------------------------------------------

export const PayoutWindowSchema = z.object({
  /** Business-timezone date, YYYY-MM-DD. */
  starts_at: z.string(),
  ends_at: z.string(),
});
export type PayoutWindow = z.infer<typeof PayoutWindowSchema>;

export const CustomerBalanceSchema = z.object({
  currency: z.string(),
  /** The HEADLINE figure. NEVER add pending_laari to it. */
  confirmed_laari: z.number().int(),
  /** Conditional money — show separately and always as conditional. */
  pending_laari: z.number().int(),
  paid_this_month_laari: z.number().int(),
  /** §13: below this, the balance carries forward to next month. */
  minimum_payout_laari: z.number().int(),
  /** When cashback confirming right now would be paid. */
  next_payout_window: PayoutWindowSchema,
  has_payout_account: z.boolean(),
});
export type CustomerBalance = z.infer<typeof CustomerBalanceSchema>;

export const CustomerBalanceResponseSchema = dataWrapped(CustomerBalanceSchema);
export type CustomerBalanceResponse = z.infer<
  typeof CustomerBalanceResponseSchema
>;

export function getCustomerBalance(
  options: RequestOptions = {},
): Promise<CustomerBalanceResponse> {
  return apiFetch('/api/customer/balance', CustomerBalanceResponseSchema, {
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// GET /api/customer/transactions
// ---------------------------------------------------------------------------

/**
 * The §6 customer-facing status: four internal states collapse into
 * `pending`; `written_off` surfaces as `unpaid` (§9.4 factual wording is the
 * frontend's job).
 */
export const CustomerTransactionStatusSchema = z.enum([
  'pending',
  'confirmed',
  'paid',
  'reversed',
  'unpaid',
]);
export type CustomerTransactionStatus = z.infer<
  typeof CustomerTransactionStatusSchema
>;

/**
 * The reason keys CustomerFacingStatus::reasonKey derives from the §6 state
 * alone — one per pending/terminal state, independent of the row's stored
 * reason_code.
 */
export const CUSTOMER_STATE_REASON_KEYS = [
  'validation_window',
  'merchant_settlement_window',
  'under_review',
  'merchant_not_settled',
  'reversed',
] as const;
export type CustomerStateReasonKey =
  (typeof CUSTOMER_STATE_REASON_KEYS)[number];

/**
 * Everything `status_reason` can be. A reversed row echoes its stored
 * `reason_code` instead of a state key (CustomerFacingStatus::reasonKey), so
 * the customer app must be able to say every §6 reason code in plain words
 * too — hence the union with TRANSACTION_REASON_CODES rather than a
 * hand-kept shortlist that silently fell behind the API.
 */
export const CUSTOMER_STATUS_REASON_KEYS = [
  ...CUSTOMER_STATE_REASON_KEYS,
  ...TRANSACTION_REASON_CODES,
] as const;
export type CustomerStatusReasonKey =
  | CustomerStateReasonKey
  | TransactionReasonCode;

/** Narrows a `status_reason` string from the wire onto the union above. */
export function isCustomerStatusReasonKey(
  value: string,
): value is CustomerStatusReasonKey {
  return (CUSTOMER_STATUS_REASON_KEYS as readonly string[]).includes(value);
}

/**
 * A transaction as the CUSTOMER sees it: no internal state, no merchant
 * commercial terms (the platform fee, and the rate the merchant is billed
 * at — neither its amount nor its percent). `status_reason` is a
 * translatable KEY, not prose — e.g. merchant_settlement_window renders as
 * "Store X settles within 15 days"; null for confirmed/paid.
 */
export const CustomerTransactionSchema = z.object({
  id: z.number().int(),
  merchant: z.object({
    name: z.string(),
    slug: z.string(),
  }),
  invoice_no: z.string(),
  currency: z.string(),
  eligible_laari: z.number().int(),
  cashback_laari: z.number().int(),
  status: CustomerTransactionStatusSchema,
  status_reason: z.string().nullable(),
  occurred_at: z.string(),
});
export type CustomerTransaction = z.infer<typeof CustomerTransactionSchema>;

export const CustomerTransactionListResponseSchema = paginated(
  CustomerTransactionSchema,
);
export type CustomerTransactionListResponse = z.infer<
  typeof CustomerTransactionListResponseSchema
>;

/** GET /api/customer/transactions — newest first, 25 per page (max 100). */
export function listCustomerTransactions(
  params: { page?: number; per_page?: number } = {},
  options: RequestOptions = {},
): Promise<CustomerTransactionListResponse> {
  return apiFetch(
    `/api/customer/transactions${queryString({
      page: params.page,
      per_page: params.per_page,
    })}`,
    CustomerTransactionListResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Payouts — the money that actually reached the bank
// ---------------------------------------------------------------------------

/**
 * `sent` — in a bank file, awaiting the bank's result.
 * `paid` — the bank confirmed it.
 * `failed` — the bank rejected it; the cashback returns to the next batch.
 *
 * `pending` exists server-side but is never listed: it belongs to a draft
 * batch nobody has approved, and showing it would promise money on a date
 * the platform has not committed to.
 */
export const CustomerPayoutStatusSchema = z.enum(['sent', 'paid', 'failed']);
export type CustomerPayoutStatus = z.infer<typeof CustomerPayoutStatusSchema>;

export const CustomerPayoutSchema = z.object({
  id: z.number().int(),
  currency: z.string(),
  amount_laari: z.number().int(),
  status: CustomerPayoutStatusSchema,
  /** A key to translate (e.g. `account_closed`), never prose. */
  failure_reason: z.string().nullable(),
  bank: z.string().nullable(),
  /** Last four digits only — "•••• 4821". */
  account_masked: z.string().nullable(),
  /** What the customer's own bank statement shows against the credit. */
  reference: z.string().nullable(),
  period_start: z.string().nullable(),
  period_end: z.string().nullable(),
  transaction_count: z.number().int().optional(),
  paid_at: z.string().nullable(),
});
export type CustomerPayout = z.infer<typeof CustomerPayoutSchema>;

/** One purchase covered by a payout. */
export const CustomerPayoutTransactionSchema = z.object({
  id: z.number().int(),
  invoice_no: z.string().nullable(),
  occurred_at: z.string(),
  eligible_laari: z.number().int(),
  cashback_laari: z.number().int(),
  merchant: z.object({
    name: z.string(),
    name_dv: z.string().nullable().catch(null),
    slug: z.string(),
  }),
});
export type CustomerPayoutTransaction = z.infer<
  typeof CustomerPayoutTransactionSchema
>;

export const CustomerPayoutDetailSchema = CustomerPayoutSchema.omit({
  transaction_count: true,
}).extend({
  transactions: z.array(CustomerPayoutTransactionSchema),
});
export type CustomerPayoutDetail = z.infer<typeof CustomerPayoutDetailSchema>;

export const CustomerPayoutListResponseSchema = paginated(CustomerPayoutSchema);
export type CustomerPayoutListResponse = z.infer<
  typeof CustomerPayoutListResponseSchema
>;

export const CustomerPayoutDetailResponseSchema = dataWrapped(
  CustomerPayoutDetailSchema,
);
export type CustomerPayoutDetailResponse = z.infer<
  typeof CustomerPayoutDetailResponseSchema
>;

/** GET /api/customer/payouts — newest first, 25 per page (max 100). */
export function listCustomerPayouts(
  params: { page?: number; per_page?: number } = {},
  options: RequestOptions = {},
): Promise<CustomerPayoutListResponse> {
  return apiFetch(
    `/api/customer/payouts${queryString({
      page: params.page,
      per_page: params.per_page,
    })}`,
    CustomerPayoutListResponseSchema,
    { signal: options.signal },
  );
}

/** GET /api/customer/payouts/{id} — the payout and what it paid for. */
export function getCustomerPayout(
  id: number,
  options: RequestOptions = {},
): Promise<CustomerPayoutDetailResponse> {
  return apiFetch(
    `/api/customer/payouts/${id}`,
    CustomerPayoutDetailResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Payout account
// ---------------------------------------------------------------------------

export const PayoutAccountSchema = z.object({
  bank_name: z.string().nullable(),
  account_no: z.string().nullable(),
  account_name: z.string().nullable(),
  has_payout_account: z.boolean(),
  /**
   * A key, not prose: a change made while a payout batch is processing is
   * safe — the in-flight batch pays the snapshotted account, and the change
   * applies from the next batch build.
   */
  change_effective: z.literal('next_batch'),
});
export type PayoutAccount = z.infer<typeof PayoutAccountSchema>;

export const PayoutAccountResponseSchema = dataWrapped(PayoutAccountSchema);
export type PayoutAccountResponse = z.infer<typeof PayoutAccountResponseSchema>;

/** GET /api/customer/payout-account */
export function getCustomerPayoutAccount(
  options: RequestOptions = {},
): Promise<PayoutAccountResponse> {
  return apiFetch(
    '/api/customer/payout-account',
    PayoutAccountResponseSchema,
    { signal: options.signal },
  );
}

export const SavePayoutAccountRequestSchema = z.object({
  bank_name: BankSlugSchema,
  /** Digits only, 6–32. */
  account_no: z.string().regex(/^\d{6,32}$/),
  account_name: z.string().min(1).max(120),
});
export type SavePayoutAccountRequest = z.infer<
  typeof SavePayoutAccountRequestSchema
>;

/** POST /api/customer/payout-account — saves (or replaces) the account. */
export function saveCustomerPayoutAccount(
  body: SavePayoutAccountRequest,
  options: RequestOptions = {},
): Promise<PayoutAccountResponse> {
  return apiFetch(
    '/api/customer/payout-account',
    PayoutAccountResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Missing-transaction claims
// ---------------------------------------------------------------------------

/**
 * A claim as its owning customer sees it. `resolution_note` carries the
 * factual rejection wording (§9.4); resolver identity stays internal.
 */
export const CustomerClaimSchema = z.object({
  id: z.number().int(),
  merchant: z.object({
    name: z.string(),
    slug: z.string(),
  }),
  /** Business-timezone date, YYYY-MM-DD. */
  purchased_at: z.string(),
  amount_laari: z.number().int(),
  currency: z.string(),
  receipt_no: z.string(),
  note: z.string().nullable(),
  state: ClaimStateSchema,
  resolution_note: z.string().nullable(),
  created_at: z.string(),
});
export type CustomerClaim = z.infer<typeof CustomerClaimSchema>;

export const CustomerClaimListResponseSchema = paginated(CustomerClaimSchema);
export type CustomerClaimListResponse = z.infer<
  typeof CustomerClaimListResponseSchema
>;

export const CustomerClaimResponseSchema = dataWrapped(CustomerClaimSchema);
export type CustomerClaimResponse = z.infer<typeof CustomerClaimResponseSchema>;

/** GET /api/customer/claims — newest first, 25 per page (max 100). */
export function listCustomerClaims(
  params: { page?: number; per_page?: number } = {},
  options: RequestOptions = {},
): Promise<CustomerClaimListResponse> {
  return apiFetch(
    `/api/customer/claims${queryString({
      page: params.page,
      per_page: params.per_page,
    })}`,
    CustomerClaimListResponseSchema,
    { signal: options.signal },
  );
}

export const CreateClaimRequestSchema = z.object({
  /**
   * Merchant slug from the public discovery feed — the only merchant
   * identifier customers ever see (discovery's privacy contract exposes no
   * internal ids). The API resolves it server-side.
   */
  merchant_slug: z.string().min(1),
  /**
   * Business-timezone date, YYYY-MM-DD. Must be within the 90-day claim
   * window and never in the future.
   */
  purchased_at: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  /** Integer laari, >= 1. */
  amount_laari: z.number().int().min(1),
  receipt_no: z.string().min(1).max(64),
  note: z.string().max(1000).optional(),
});
export type CreateClaimRequest = z.infer<typeof CreateClaimRequestSchema>;

/**
 * POST /api/customer/claims — opens a claim for the admin queue (201).
 * Nothing accrues until an admin approves it there.
 */
export function createCustomerClaim(
  body: CreateClaimRequest,
  options: RequestOptions = {},
): Promise<CustomerClaimResponse> {
  return apiFetch('/api/customer/claims', CustomerClaimResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}
