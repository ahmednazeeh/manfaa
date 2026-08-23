import { z } from "zod";
import { apiFetch } from "./client";
import {
  BankSlugSchema,
  ClaimStateSchema,
  dataWrapped,
  paginated,
  TRANSACTION_REASON_CODES,
  type TransactionReasonCode,
} from "./resources";

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
  return encoded === "" ? "" : `?${encoded}`;
}

// ---------------------------------------------------------------------------
// The customer identity
// ---------------------------------------------------------------------------

export const CustomerSchema = z.object({
  id: z.number().int(),
  /** The 6-digit code quoted at the till. */
  customer_code: z.string(),
  name: z.string(),
  /**
   * The same name in Thaana, written at registration by transliterating
   * `name`. Nullable forever: the writer is allowed to fail, and a customer
   * may clear it — both mean "show `name`".
   */
  name_dv: z.string().nullish().default(null),
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
  return apiFetch("/api/customer/auth/login", CustomerResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

/** POST /api/customer/auth/logout — 204. */
export async function customerLogout(
  options: RequestOptions = {},
): Promise<void> {
  await apiFetch("/api/customer/auth/logout", z.undefined(), {
    method: "POST",
    signal: options.signal,
  });
}

/** GET /api/customer/auth/me — the authenticated customer. */
export function getCustomerMe(
  options: RequestOptions = {},
): Promise<CustomerResponse> {
  return apiFetch("/api/customer/auth/me", CustomerResponseSchema, {
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
  body.append("avatar", file);

  return apiFetch("/api/customer/avatar", AvatarResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

/** DELETE /api/customer/avatar — removes the picture; idempotent. */
export function removeCustomerAvatar(
  options: RequestOptions = {},
): Promise<AvatarResponse> {
  return apiFetch("/api/customer/avatar", AvatarResponseSchema, {
    method: "DELETE",
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
  return apiFetch("/api/customer/auth/request-otp", RequestOtpResponseSchema, {
    method: "POST",
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
    /** Revealed only after OTP proof: this number already has an account. */
    already_registered: z.boolean().optional(),
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
  return apiFetch("/api/customer/auth/verify-otp", VerifyOtpResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

export const RegisterCustomerRequestSchema = z.object({
  signup_token: z.string().min(1),
  name: z.string().min(1).max(120),
});
export type RegisterCustomerRequest = z.infer<
  typeof RegisterCustomerRequestSchema
>;

/**
 * POST /api/customer/auth/otp/verify — the passwordless sign-in (owner
 * decision 2026-08-18). A KNOWN number is signed straight into the session
 * and comes back as the customer; an unknown one comes back with a
 * `signup_token` to finish registration with a name. 422 keys:
 * `otp_invalid`, `otp_attempts_exceeded`.
 */
export const OtpAccessResponseSchema = z.union([
  CustomerResponseSchema,
  z.object({
    data: z.object({
      signup_token: z.string(),
      expires_in_minutes: z.number().int(),
    }),
  }),
]);
export type OtpAccessResponse = z.infer<typeof OtpAccessResponseSchema>;

export function verifyOtpForAccess(
  body: { phone: string; code: string },
  options: RequestOptions = {},
): Promise<OtpAccessResponse> {
  return apiFetch("/api/customer/auth/otp/verify", OtpAccessResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

/**
 * POST /api/customer/auth/register — creates the account (201) and logs the
 * session in. 422 keys: `signup_token_invalid`, `phone_already_registered`.
 */
export function registerCustomer(
  body: RegisterCustomerRequest,
  options: RequestOptions = {},
): Promise<CustomerResponse> {
  return apiFetch("/api/customer/auth/register", CustomerResponseSchema, {
    method: "POST",
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
  return apiFetch("/api/customer/balance", CustomerBalanceResponseSchema, {
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
  "pending",
  "confirmed",
  "paid",
  "reversed",
  "unpaid",
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
  "validation_window",
  "merchant_settlement_window",
  "under_review",
  "merchant_not_settled",
  "reversed",
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
  CustomerStateReasonKey | TransactionReasonCode;

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
export const CustomerPayoutStatusSchema = z.enum(["sent", "paid", "failed"]);
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
  change_effective: z.literal("next_batch"),
});
export type PayoutAccount = z.infer<typeof PayoutAccountSchema>;

export const PayoutAccountResponseSchema = dataWrapped(PayoutAccountSchema);
export type PayoutAccountResponse = z.infer<typeof PayoutAccountResponseSchema>;

/** GET /api/customer/payout-account */
export function getCustomerPayoutAccount(
  options: RequestOptions = {},
): Promise<PayoutAccountResponse> {
  return apiFetch("/api/customer/payout-account", PayoutAccountResponseSchema, {
    signal: options.signal,
  });
}

export const SavePayoutAccountRequestSchema = z.object({
  bank_name: BankSlugSchema,
  /** Digits only, 6–32. */
  account_no: z.string().regex(/^\d{6,32}$/),
  account_name: z.string().min(1).max(120),
  /** The fresh possession proof — same gate as the app (2026-08-17). */
  otp_code: z.string().regex(/^\d{6}$/),
});
export type SavePayoutAccountRequest = z.infer<
  typeof SavePayoutAccountRequestSchema
>;

export const PayoutOtpResponseSchema = dataWrapped(
  z.object({
    sent: z.literal(true),
    expires_in_minutes: z.number().int(),
  }),
);
export type PayoutOtpResponse = z.infer<typeof PayoutOtpResponseSchema>;

/**
 * POST /api/customer/payout-account/otp — sends the confirmation code to
 * the customer's own number on file. Saving requires the code.
 */
export function requestPayoutAccountOtp(
  options: RequestOptions = {},
): Promise<PayoutOtpResponse> {
  return apiFetch("/api/customer/payout-account/otp", PayoutOtpResponseSchema, {
    method: "POST",
    signal: options.signal,
  });
}

/** POST /api/customer/payout-account — saves (or replaces) the account. */
export function saveCustomerPayoutAccount(
  body: SavePayoutAccountRequest,
  options: RequestOptions = {},
): Promise<PayoutAccountResponse> {
  return apiFetch("/api/customer/payout-account", PayoutAccountResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
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
  return apiFetch("/api/customer/claims", CustomerClaimResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// MARKETPLACE — the shopper's side on the web (PLAN-marketplace.md §3)
// ---------------------------------------------------------------------------

export const WebDeliveryTermsSchema = z.object({
  delivers: z.boolean(),
  fee_laari: z.number().int(),
  fee_waived: z.boolean(),
  free_delivery_over_laari: z.number().int().nullable(),
  order_minimum_laari: z.number().int().nullable(),
  minimum_met: z.boolean(),
  shortfall_laari: z.number().int(),
  to_free_delivery_laari: z.number().int().nullable(),
  eta_min: z.number().int().nullable(),
  eta_max: z.number().int().nullable(),
});

/** A storefront. A BRANCH, not a merchant — the shop is what you buy from. */
export const MarketBranchSchema = z.object({
  branch_id: z.number().int(),
  merchant_id: z.number().int(),
  store_name: z.string(),
  store_name_dv: z.string().nullable(),
  branch_name: z.string(),
  slug: z.string(),
  address: z.string().nullable(),
  cashback_rate_percent: z.string().nullable(),
  fulfilment: z.string().nullable(),
  /** Null until somebody has rated it — never 0.0. */
  rating: z.number().nullable(),
  rating_count: z.number().int(),
  delivery: WebDeliveryTermsSchema,
  pickup_only: z.boolean(),
});
export type MarketBranch = z.infer<typeof MarketBranchSchema>;

export function listMarketBranches(addressId?: number, options: RequestOptions = {}) {
  const query = addressId === undefined ? '' : `?address_id=${addressId}`;

  return apiFetch(
    `/api/market/branches${query}`,
    z.object({
      data: z.array(MarketBranchSchema),
      meta: z.object({
        address_id: z.number().int().nullable(),
        needs_address: z.boolean(),
      }),
    }),
    { signal: options.signal },
  );
}

export const MarketProductSchema = z.object({
  branch_product_id: z.number().int(),
  product_id: z.number().int(),
  name: z.string(),
  name_dv: z.string().nullable(),
  description: z.string().nullable(),
  price_laari: z.number().int(),
  compare_at_laari: z.number().int().nullable(),
  cashback_rate_percent: z.string().nullable(),
  image_url: z.string().nullable(),
  in_stock: z.boolean(),
  category: z.string().nullable(),
});
export type MarketProduct = z.infer<typeof MarketProductSchema>;

export const MarketStoreSchema = z.object({
  branch_id: z.number().int(),
  store_name: z.string(),
  branch_name: z.string(),
  address: z.string().nullable(),
  rating: z.number().nullable(),
  rating_count: z.number().int(),
  delivery: WebDeliveryTermsSchema,
  cashback_rate_percent: z.string().nullable(),
  categories: z.array(
    z.object({
      slug: z.string(),
      name_en: z.string(),
      name_dv: z.string().nullable(),
    }),
  ),
  products: z.array(MarketProductSchema),
});
export type MarketStore = z.infer<typeof MarketStoreSchema>;

export function getMarketStore(
  branchId: number,
  params: { category?: string; addressId?: number } = {},
  options: RequestOptions = {},
) {
  const query = new URLSearchParams();
  if (params.category) query.set('category', params.category);
  if (params.addressId !== undefined) query.set('address_id', String(params.addressId));

  return apiFetch(
    `/api/market/branches/${branchId}${query.size ? `?${query}` : ''}`,
    dataWrapped(MarketStoreSchema),
    { signal: options.signal },
  );
}

// ------------------------------------------------------------------- cart

export const CartLineSchema = z.object({
  cart_item_id: z.number().int(),
  branch_product_id: z.number().int(),
  product_id: z.number().int(),
  name: z.string(),
  name_dv: z.string().nullable(),
  qty: z.number().int(),
  unit_price_laari: z.number().int(),
  line_total_laari: z.number().int(),
  cashback_laari: z.number().int(),
  /** Said out loud rather than applied silently. */
  price_changed: z.boolean(),
  price_was_laari: z.number().int().nullable(),
  /** Flagged where it sits — a row that vanishes reads as a bug. */
  available: z.boolean(),
  stock_qty: z.number().int().nullable(),
});

export const SubcartSchema = z.object({
  branch_id: z.number().int(),
  merchant_id: z.number().int(),
  store_name: z.string(),
  branch_name: z.string(),
  items: z.array(CartLineSchema),
  items_laari: z.number().int(),
  cashback_laari: z.number().int(),
  cashback_rate_percent: z.string().nullable(),
  /**
   * The store's minimum eligible sale — the same rule its till is held to.
   * Under it the shop's cashback is 0 and `cashback_shortfall_laari` says
   * how much more would earn it. Older servers omit these.
   */
  cashback_min_laari: z.number().int().optional().default(0),
  below_cashback_minimum: z.boolean().optional().default(false),
  cashback_shortfall_laari: z.number().int().optional().default(0),
  delivery: WebDeliveryTermsSchema,
  all_available: z.boolean(),
});
export type Subcart = z.infer<typeof SubcartSchema>;

export const CartSchema = z.object({
  subcarts: z.array(SubcartSchema),
  items_laari: z.number().int(),
  delivery_laari: z.number().int(),
  total_payable_laari: z.number().int(),
  cashback_laari: z.number().int(),
  store_count: z.number().int(),
  /** One boolean over every subcart. */
  can_checkout: z.boolean(),
  needs_address: z.boolean(),
  address_id: z.number().int().nullable(),
});
export type Cart = z.infer<typeof CartSchema>;

const CartResponseSchema = dataWrapped(CartSchema);

export function getCart(addressId?: number, options: RequestOptions = {}) {
  const query = addressId === undefined ? '' : `?address_id=${addressId}`;

  return apiFetch(`/api/customer/cart${query}`, CartResponseSchema, {
    signal: options.signal,
  });
}

export function addToCart(branchProductId: number, qty = 1) {
  return apiFetch('/api/customer/cart/items', CartResponseSchema, {
    method: 'POST',
    body: { branch_product_id: branchProductId, qty },
  });
}

export function setCartQty(cartItemId: number, qty: number) {
  return apiFetch(`/api/customer/cart/items/${cartItemId}`, CartResponseSchema, {
    method: 'PATCH',
    body: { qty },
  });
}

export function clearCart() {
  return apiFetch('/api/customer/cart', CartResponseSchema, { method: 'DELETE' });
}

// ----------------------------------------------------------------- orders

export const CustomerAddressSchema = z.object({
  id: z.number().int(),
  label: z.string(),
  recipient_name: z.string(),
  phone: z.string(),
  building: z.string(),
  island: z.string().nullable(),
  area_magu: z.string().nullable(),
  apartment_floor: z.string().nullable(),
  delivery_note: z.string().nullable(),
  lat: z.number().nullable(),
  lng: z.number().nullable(),
  /** Resolved from the pin. Null = no shop can quote delivery there yet. */
  zone_id: z.number().int().nullable(),
  zone_name: z.string().nullable(),
  is_default: z.boolean(),
});
export type CustomerAddress = z.infer<typeof CustomerAddressSchema>;

export function listAddresses(options: RequestOptions = {}) {
  return apiFetch(
    '/api/customer/addresses',
    z.object({ data: z.array(CustomerAddressSchema) }),
    { signal: options.signal },
  );
}

export function createAddress(body: Record<string, unknown>) {
  return apiFetch('/api/customer/addresses', dataWrapped(CustomerAddressSchema), {
    method: 'POST',
    body,
  });
}

export const CustomerOrderSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  state: z.string(),
  payment_state: z.string(),
  payment_method: z.string(),
  items_laari: z.number().int(),
  delivery_laari: z.number().int(),
  total_payable_laari: z.number().int(),
  cashback_total_laari: z.number().int(),
  store_count: z.number().int(),
  placed_at: z.string().nullable(),
  address: z.record(z.string(), z.unknown()).nullable(),
  suborders: z.array(
    z.object({
      id: z.number().int(),
      reference: z.string(),
      store_name: z.string().nullable(),
      branch_name: z.string().nullable(),
      fulfilment: z.string(),
      state: z.string(),
      reject_reason: z.string().nullable(),
      pickup_code: z.string().nullable(),
      items_laari: z.number().int(),
      delivery_laari: z.number().int(),
      subtotal_laari: z.number().int(),
      cashback_laari: z.number().int(),
      items: z.array(
        z.object({
          id: z.number().int(),
          name: z.string(),
          qty: z.number().int(),
          fulfilled_qty: z.number().int(),
          amended: z.boolean(),
          refund_laari: z.number().int(),
          unit_price_laari: z.number().int(),
          line_total_laari: z.number().int(),
        }),
      ),
    }),
  ),
});
export type CustomerOrder = z.infer<typeof CustomerOrderSchema>;

export function listCustomerOrders(options: RequestOptions = {}) {
  return apiFetch(
    '/api/customer/orders',
    z.object({ data: z.array(CustomerOrderSchema) }),
    { signal: options.signal },
  );
}

export function getCustomerOrder(id: number, options: RequestOptions = {}) {
  return apiFetch(`/api/customer/orders/${id}`, dataWrapped(CustomerOrderSchema), {
    signal: options.signal,
  });
}

export function placeOrder(body: { payment_method: string; address_id?: number | null }) {
  return apiFetch('/api/customer/orders', dataWrapped(CustomerOrderSchema), {
    method: 'POST',
    body,
  });
}

export function listPaymentAccounts(options: RequestOptions = {}) {
  return apiFetch(
    '/api/customer/payment-accounts',
    z.object({
      data: z.array(
        z.object({
          id: z.number().int(),
          bank_name: z.string(),
          account_no: z.string(),
          account_name: z.string(),
          currency: z.string(),
          is_primary: z.boolean(),
        }),
      ),
    }),
    { signal: options.signal },
  );
}

// ----------------------------------------------------------------- wallet

const CustomerWalletSchema = dataWrapped(
  z.object({
    balance_laari: z.number().int(),
    currency: z.string(),
    minimum_withdrawal_laari: z.number().int(),
    can_withdraw: z.boolean(),
    has_bank_account: z.boolean(),
    entries: z.array(
      z.object({
        id: z.number().int(),
        amount_laari: z.number().int(),
        balance_after_laari: z.number().int(),
        type: z.string(),
        description: z.string().nullable(),
        at: z.string().nullable(),
      }),
    ),
    withdrawals: z.array(
      z.object({
        id: z.number().int(),
        amount_laari: z.number().int(),
        state: z.string(),
        requested_at: z.string().nullable(),
        /** The BANK's reference — never an approval-queue id. */
        bank_reference: z.string().nullable(),
      }),
    ),
  }),
);

export function getWallet(options: RequestOptions = {}) {
  return apiFetch('/api/customer/wallet', CustomerWalletSchema, {
    signal: options.signal,
  });
}

export function requestWithdrawal(amountLaari: number) {
  return apiFetch(
    '/api/customer/wallet/withdrawals',
    dataWrapped(
      z.object({
        id: z.number().int(),
        amount_laari: z.number().int(),
        state: z.string(),
        balance_laari: z.number().int(),
      }),
    ),
    { method: 'POST', body: { amount_laari: amountLaari } },
  );
}

// -------------------------------------------------------------- referrals

/**
 * One invited friend on the referral page. PRIVACY: the API masks the name
 * and CAPS `spent_laari` at the programme threshold — it is progress toward
 * the bonus, never the friend's real spending.
 */
export const ReferralFriendSchema = z.object({
  /** Masked by the API (e.g. "Ah***ed") — never the full name. */
  name: z.string(),
  /** ISO-8601; null only for legacy rows with no timestamp at all. */
  joined_at: z.string().nullable(),
  /** Capped at `threshold_laari`; equals it once `rewarded` is true. */
  spent_laari: z.number().int(),
  /** True once this friend has earned the referrer their one-time bonus. */
  rewarded: z.boolean(),
});
export type ReferralFriend = z.infer<typeof ReferralFriendSchema>;

/**
 * GET /api/customer/referrals — the customer's referral code (their own
 * 6-digit `customer_code`), the programme's live figures, and every friend
 * they have brought in.
 */
export const ReferralsSummarySchema = z.object({
  /** Is the programme currently awarding bonuses? (Superadmin switch.) */
  enabled: z.boolean(),
  /** The bonus the referrer earns per qualified friend, integer laari. */
  reward_laari: z.number().int(),
  /** Validated spend a friend must reach to qualify, integer laari. */
  threshold_laari: z.number().int(),
  /** The referral code IS the customer's own 6-digit till code. */
  code: z.string(),
  share_url: z.string(),
  stats: z.object({
    invited: z.number().int(),
    rewarded: z.number().int(),
    /** Read from the wallet ledger — honest across reward-figure changes. */
    earned_total_laari: z.number().int(),
  }),
  friends: z.array(ReferralFriendSchema),
});
export type ReferralsSummary = z.infer<typeof ReferralsSummarySchema>;

export function getReferrals(options: RequestOptions = {}) {
  return apiFetch('/api/customer/referrals', dataWrapped(ReferralsSummarySchema), {
    signal: options.signal,
  });
}
