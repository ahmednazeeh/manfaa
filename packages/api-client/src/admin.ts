import { z } from "zod";
import { apiBaseUrl, apiFetch, apiFetchBlob } from "./client";
import {
  BankSlugSchema,
  CashbackPercentInputSchema,
  ClaimStateSchema,
  dataWrapped,
  FeePercentInputSchema,
  MerchantChannelSchema,
  MerchantStatusSchema,
  paginated,
  PayoutBatchSchema,
  PercentSchema,
  PromotionSchema,
  SettlementPaymentSchema,
  SettlementSchema,
  WalletTopUpSchema,
  type ClaimState,
  type PromotionStatus,
  type SettlementState,
  type WalletTopUpState,
} from "./resources";

/**
 * Typed contracts for the admin surface: the settlement matching queue,
 * merchant standing and notices, reconciliation runs, the payout batch
 * lifecycle (Phase 1), the claims queue and promotions read model (Phase 3),
 * and the platform settings domain — platform bank accounts, the §4 fee tier
 * schedule, typed platform settings, superadmin-only admin account
 * management, and island zoning (polygon CRUD with server-side branch
 * assignment). All amounts sent and received are integer laari; all RATES
 * travel as 2-decimal percent strings (PLAN §1 wire format) — basis points
 * are the API's internal representation and never appear in a body.
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
// Settlement queue
// ---------------------------------------------------------------------------

export const AdminSettlementListResponseSchema = paginated(SettlementSchema);
export type AdminSettlementListResponse = z.infer<
  typeof AdminSettlementListResponseSchema
>;

export const AdminSettlementResponseSchema = dataWrapped(SettlementSchema);
export type AdminSettlementResponse = z.infer<
  typeof AdminSettlementResponseSchema
>;

/** GET /api/admin/settlements — optionally filtered by state, 25 per page. */
export function listAdminSettlements(
  params: { state?: SettlementState; page?: number } = {},
  options: RequestOptions = {},
): Promise<AdminSettlementListResponse> {
  return apiFetch(
    `/api/admin/settlements${queryString({
      state: params.state,
      page: params.page,
    })}`,
    AdminSettlementListResponseSchema,
    { signal: options.signal },
  );
}

/** GET /api/admin/settlements/{id} — with lines (incl. transaction) and payments. */
export function getAdminSettlement(
  id: number,
  options: RequestOptions = {},
): Promise<AdminSettlementResponse> {
  return apiFetch(
    `/api/admin/settlements/${id}`,
    AdminSettlementResponseSchema,
    { signal: options.signal },
  );
}

export const RecordSettlementPaymentRequestSchema = z.object({
  /** Integer laari, >= 1. */
  amount: z.number().int().min(1),
  bank_ref: z.string().min(1).max(128),
  slip_path: z.string().max(255).optional(),
});
export type RecordSettlementPaymentRequest = z.infer<
  typeof RecordSettlementPaymentRequestSchema
>;

export const SettlementPaymentResponseSchema = dataWrapped(
  SettlementPaymentSchema,
);
export type SettlementPaymentResponse = z.infer<
  typeof SettlementPaymentResponseSchema
>;

/** POST /api/admin/settlements/{id}/payments — records a claimed bank payment (201). */
export function recordAdminSettlementPayment(
  settlementId: number,
  body: RecordSettlementPaymentRequest,
  options: RequestOptions = {},
): Promise<SettlementPaymentResponse> {
  return apiFetch(
    `/api/admin/settlements/${settlementId}/payments`,
    SettlementPaymentResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/** POST /api/admin/payments/{id}/match — confirms a payment; returns the settlement. */
export function matchAdminSettlementPayment(
  paymentId: number,
  options: RequestOptions = {},
): Promise<AdminSettlementResponse> {
  return apiFetch(
    `/api/admin/payments/${paymentId}/match`,
    AdminSettlementResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

export const RejectSettlementRequestSchema = z.object({
  /** Recorded on the refused payment; the merchant reads it verbatim. */
  reason: z.string().min(3).max(1000),
});
export type RejectSettlementRequest = z.infer<
  typeof RejectSettlementRequestSchema
>;

/**
 * POST /api/admin/settlements/{id}/reject — the second review outcome
 * (PLAN §1 receipt-first): the transfer could not be verified, so the batch
 * cancels and its lines release, leaving the transactions payable again for a
 * fresh merchant-submitted settlement. Only a batch in `payment_review` with
 * nothing received can be rejected; anything else answers 409.
 */
export function rejectAdminSettlement(
  settlementId: number,
  body: RejectSettlementRequest,
  options: RequestOptions = {},
): Promise<AdminSettlementResponse> {
  return apiFetch(
    `/api/admin/settlements/${settlementId}/reject`,
    AdminSettlementResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/**
 * Path of the authenticated slip stream — the ONLY way a merchant's uploaded
 * receipt is ever read, since the `slips` disk is private and unserved. Fetch
 * it credentialed (it is not a public URL); `payment_id` names an earlier slip
 * when a batch carries several.
 */
export function adminSettlementSlipPath(
  settlementId: number,
  paymentId?: number,
): string {
  return `/api/admin/settlements/${settlementId}/slip${queryString({
    payment_id: paymentId,
  })}`;
}

/**
 * Absolute URL of the same stream, for an <img>/<iframe> src. The request
 * carries the admin session cookie (SESSION_DOMAIN spans the panel and the
 * API), and the response is served inline with the mime the uploaded BYTES
 * declared plus `nosniff` — so a PDF renders as a PDF and nothing renders as
 * script. It is NOT a shareable link: without the admin session it is a 401,
 * which in an <img> shows only as a broken image. Prefer
 * `fetchAdminSettlementSlip` when the screen must tell a missing slip (404)
 * apart from an expired session.
 */
export function adminSettlementSlipUrl(
  settlementId: number,
  paymentId?: number,
): string {
  return `${apiBaseUrl()}${adminSettlementSlipPath(settlementId, paymentId)}`;
}

/**
 * GET /api/admin/settlements/{id}/slip as a Blob — the reviewable receipt,
 * with real error handling: 404 when the batch carries no slip (or the stored
 * file is gone). Wrap it in `URL.createObjectURL` for the preview and revoke
 * the object URL when it closes.
 */
export function fetchAdminSettlementSlip(
  settlementId: number,
  paymentId?: number,
  options: RequestOptions = {},
): Promise<Blob> {
  return apiFetchBlob(adminSettlementSlipPath(settlementId, paymentId), {
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Wallet top-up queue (owner, 2026-08-24)
// ---------------------------------------------------------------------------

/**
 * The admin side of merchant wallet top-ups: the fallback when the
 * bank-history verifier could not find a merchant's transfer. Listing by
 * state, the slip, and the two review outcomes — Match (credits the wallet
 * through the same path the verifier uses) or Reject (with a reason the
 * merchant reads verbatim). Any admin, like the settlement payment match —
 * reconciling a transfer against a statement is queue work, not a
 * superadmin lever. Each row carries `merchant` and `platform_bank_account`
 * embedded.
 */
export const AdminWalletTopUpListResponseSchema = paginated(WalletTopUpSchema);
export type AdminWalletTopUpListResponse = z.infer<
  typeof AdminWalletTopUpListResponseSchema
>;

export const AdminWalletTopUpResponseSchema = dataWrapped(WalletTopUpSchema);
export type AdminWalletTopUpResponse = z.infer<
  typeof AdminWalletTopUpResponseSchema
>;

/** GET /api/admin/wallet-top-ups — optionally filtered by state, newest first, 25 per page. */
export function listWalletTopUps(
  params: { state?: WalletTopUpState; page?: number } = {},
  options: RequestOptions = {},
): Promise<AdminWalletTopUpListResponse> {
  return apiFetch(
    `/api/admin/wallet-top-ups${queryString({
      state: params.state,
      page: params.page,
    })}`,
    AdminWalletTopUpListResponseSchema,
    { signal: options.signal },
  );
}

export const MatchWalletTopUpRequestSchema = z.object({
  /**
   * The bank's reference for the transfer, as the admin reads it off the
   * statement. REQUIRED when the merchant typed none (the row's `bank_ref`
   * is null): the wallet movement's idempotency key is the reference, and a
   * credit without one could be booked twice. Optional otherwise — the
   * merchant's own reference is kept when both are present.
   */
  bank_ref: z.string().min(1).max(128).optional(),
});
export type MatchWalletTopUpRequest = z.infer<
  typeof MatchWalletTopUpRequestSchema
>;

/**
 * POST /api/admin/wallet-top-ups/{id}/match — confirms the transfer by hand
 * and credits the wallet in the same transaction; returns the row as
 * `matched` with its `wallet_transaction_id`. 422 when the merchant gave no
 * reference and the body carries none; 409 when the claim is no longer
 * pending (a poll matched it first, or it was already reviewed) or the
 * reference is already booked.
 */
export function matchWalletTopUp(
  id: number,
  body: MatchWalletTopUpRequest = {},
  options: RequestOptions = {},
): Promise<AdminWalletTopUpResponse> {
  return apiFetch(
    `/api/admin/wallet-top-ups/${id}/match`,
    AdminWalletTopUpResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

export const RejectWalletTopUpRequestSchema = z.object({
  /** Recorded as `rejected_reason`; the merchant reads it verbatim. */
  reason: z.string().min(3).max(1000),
});
export type RejectWalletTopUpRequest = z.infer<
  typeof RejectWalletTopUpRequestSchema
>;

/**
 * POST /api/admin/wallet-top-ups/{id}/reject — the transfer could not be
 * verified. Nothing is credited, and the claimed bank reference is released
 * so the merchant can claim it again once sorted. 409 unless pending.
 */
export function rejectWalletTopUp(
  id: number,
  body: RejectWalletTopUpRequest,
  options: RequestOptions = {},
): Promise<AdminWalletTopUpResponse> {
  return apiFetch(
    `/api/admin/wallet-top-ups/${id}/reject`,
    AdminWalletTopUpResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/**
 * Path of the authenticated top-up slip stream — the ONLY way the uploaded
 * slip is ever read (same private `slips` disk as settlement receipts).
 */
export function walletTopUpSlipPath(id: number): string {
  return `/api/admin/wallet-top-ups/${id}/slip`;
}

/**
 * Absolute URL of the same stream for an <img>/<iframe> src, exactly as
 * `adminSettlementSlipUrl`: credentialed by the admin session cookie, served
 * inline with the mime the BYTES declared plus `nosniff`, a 401 without the
 * session. Prefer `fetchWalletTopUpSlip` when the screen must tell a missing
 * slip (404) apart from an expired session.
 */
export function walletTopUpSlipUrl(id: number): string {
  return `${apiBaseUrl()}${walletTopUpSlipPath(id)}`;
}

/**
 * GET /api/admin/wallet-top-ups/{id}/slip as a Blob — 404 when the claim
 * carries no slip or the stored file is gone. Wrap it in
 * `URL.createObjectURL` for the preview and revoke it when the preview
 * closes.
 */
export function fetchWalletTopUpSlip(
  id: number,
  options: RequestOptions = {},
): Promise<Blob> {
  return apiFetchBlob(walletTopUpSlipPath(id), { signal: options.signal });
}

// ---------------------------------------------------------------------------
// Merchant standing + notices
// ---------------------------------------------------------------------------

export const MerchantStandingSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  slug: z.string(),
  /**
   * The §1 merchant lifecycle, not a free string — the console renders it as
   * a chip, and an exhaustive label map only proves anything if the type it
   * is exhaustive OVER is the same one the database constrains.
   */
  status: MerchantStatusSchema,
  /** Storefront "featured" shelf placement — editorial, admin-set. */
  featured: z.boolean(),
  open_payable_count: z.number().int(),
  outstanding_laari: z.number().int(),
  overdue_laari: z.number().int(),
  oldest_due_at: z.string().nullable(),
});
export type MerchantStanding = z.infer<typeof MerchantStandingSchema>;

export const MerchantStandingListResponseSchema = z.object({
  data: z.array(MerchantStandingSchema),
});
export type MerchantStandingListResponse = z.infer<
  typeof MerchantStandingListResponseSchema
>;

/** GET /api/admin/merchants — standing with unfunded-payable totals. */
export function listAdminMerchants(
  options: RequestOptions = {},
): Promise<MerchantStandingListResponse> {
  return apiFetch("/api/admin/merchants", MerchantStandingListResponseSchema, {
    signal: options.signal,
  });
}

export const MerchantFeaturedResponseSchema = dataWrapped(
  z.object({
    id: z.number().int(),
    featured: z.boolean(),
  }),
);
export type MerchantFeaturedResponse = z.infer<
  typeof MerchantFeaturedResponseSchema
>;

/**
 * PUT /api/admin/merchants/{merchant}/featured — flips the store's placement
 * on the public "featured" shelf. The server drops the discovery cache on a
 * real change, so the storefront follows immediately rather than after the
 * 60-second TTL. Idempotent: re-sending the value already held answers 200.
 */
export function setAdminMerchantFeatured(
  merchantId: number,
  featured: boolean,
  options: RequestOptions = {},
): Promise<MerchantFeaturedResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}/featured`,
    MerchantFeaturedResponseSchema,
    { method: "PUT", body: { featured }, signal: options.signal },
  );
}

/**
 * PATCH /api/admin/merchants/{merchant}/branches/{branch} — correct a branch
 * (owner request 2026-08-18).
 *
 * A DIRECT write, not a change request: MR9 queues what a MERCHANT proposes
 * so an admin can judge it, and this is the admin. The case that asked for
 * it is a wrong address — merchants type these by hand, and a wrong one
 * sends customers to the wrong door — but the pin and the name are the same
 * class of public mistake, so all three are repairable here.
 */
export const AdminBranchUpdateResponseSchema = dataWrapped(
  z.object({
    id: z.number().int(),
    name: z.string(),
    address: z.string().nullable(),
    lat: z.number().nullable(),
    lng: z.number().nullable(),
  }),
);
export type AdminBranchUpdateResponse = z.infer<
  typeof AdminBranchUpdateResponseSchema
>;

export function updateAdminMerchantBranch(
  merchantId: number,
  branchId: number,
  body: {
    name?: string;
    address?: string;
    lat?: number | null;
    lng?: number | null;
  },
  options: RequestOptions = {},
): Promise<AdminBranchUpdateResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}/branches/${branchId}`,
    AdminBranchUpdateResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

export const MerchantNoticeTypeSchema = z.enum([
  "reminder_day10",
  "urgent_day13",
  "due_day15",
  "suspended",
  "reinstated",
  "write_off",
]);
export type MerchantNoticeType = z.infer<typeof MerchantNoticeTypeSchema>;

export const MerchantNoticeSchema = z.object({
  id: z.number().int(),
  type: MerchantNoticeTypeSchema,
  channel: z.string(),
  payload: z.record(z.string(), z.unknown()).nullable(),
  sent_at: z.string(),
});
export type MerchantNotice = z.infer<typeof MerchantNoticeSchema>;

export const MerchantNoticeListResponseSchema = z.object({
  data: z.array(MerchantNoticeSchema),
});
export type MerchantNoticeListResponse = z.infer<
  typeof MerchantNoticeListResponseSchema
>;

/** GET /api/admin/merchants/{merchant}/notices — newest first, capped at 200. */
export function listAdminMerchantNotices(
  merchantId: number,
  options: RequestOptions = {},
): Promise<MerchantNoticeListResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}/notices`,
    MerchantNoticeListResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Merchant detail + superadmin controls
// ---------------------------------------------------------------------------

export const AdminMerchantBranchSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  address: z.string().nullable(),
  lat: z.number().nullable(),
  lng: z.number().nullable(),
  /** Resolved from the pin at write time (ZoneAssigner); null = unzoned. */
  zone: z
    .object({ id: z.number().int(), name: z.string().nullable() })
    .nullable(),
});
export type AdminMerchantBranch = z.infer<typeof AdminMerchantBranchSchema>;

export const AdminMerchantStaffSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
  /** The store's own role object — names are the store's words, not ours. */
  role: z
    .object({
      id: z.number().int(),
      name: z.string(),
      name_dv: z.string().nullable(),
      is_owner: z.boolean(),
    })
    .nullable(),
  is_active: z.boolean(),
  created_at: z.string().nullable(),
});
export type AdminMerchantStaff = z.infer<typeof AdminMerchantStaffSchema>;

export const AdminMerchantDetailSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  name_dv: z.string().nullable(),
  slug: z.string(),
  status: MerchantStatusSchema,
  featured: z.boolean(),
  category: z.string().nullable(),
  channel: MerchantChannelSchema,
  eligibility_basis: z.string().nullable(),
  contact_email: z.string().nullable(),
  contact_phone: z.string().nullable(),
  support_phone: z.string().nullable(),
  website_url: z.string().nullable(),
  logo_url: z.string().nullable(),
  /** PLAN §1 wire format: 2-decimal percent string; null = no live rate. */
  cashback_rate_percent: z.string().nullable(),
  created_at: z.string().nullable(),
  submitted_at: z.string().nullable(),
  approved_at: z.string().nullable(),
  standing: z.object({
    open_payable_count: z.number().int(),
    outstanding_laari: z.number().int(),
    overdue_laari: z.number().int(),
    oldest_due_at: z.string().nullable(),
  }),
  branches: z.array(AdminMerchantBranchSchema),
  staff: z.array(AdminMerchantStaffSchema),
});
export type AdminMerchantDetail = z.infer<typeof AdminMerchantDetailSchema>;

export const AdminMerchantDetailResponseSchema = dataWrapped(
  AdminMerchantDetailSchema,
);
export type AdminMerchantDetailResponse = z.infer<
  typeof AdminMerchantDetailResponseSchema
>;

/** GET /api/admin/merchants/{merchant} — the full record for the detail drawer. */
export function getAdminMerchant(
  merchantId: number,
  options: RequestOptions = {},
): Promise<AdminMerchantDetailResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}`,
    AdminMerchantDetailResponseSchema,
    { signal: options.signal },
  );
}

export const UpdateAdminMerchantRequestSchema = z.object({
  name: z.string().min(2).max(120).optional(),
  name_dv: z.string().max(120).nullable().optional(),
  category: z.string().max(80).nullable().optional(),
  channel: MerchantChannelSchema.optional(),
  eligibility_basis: z.string().max(2000).nullable().optional(),
  contact_email: z.string().max(255).nullable().optional(),
  contact_phone: z.string().max(32).nullable().optional(),
  support_phone: z.string().max(32).nullable().optional(),
  website_url: z.string().max(255).nullable().optional(),
});
export type UpdateAdminMerchantRequest = z.infer<
  typeof UpdateAdminMerchantRequestSchema
>;

/**
 * PATCH /api/admin/merchants/{merchant} — superadmin edit over the public
 * profile, validated exactly like the merchant's own settings save. The
 * server drops the discovery read model, so the storefront reflects the
 * change immediately. Answers the fresh detail record.
 */
export function updateAdminMerchant(
  merchantId: number,
  body: UpdateAdminMerchantRequest,
  options: RequestOptions = {},
): Promise<AdminMerchantDetailResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}`,
    AdminMerchantDetailResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

export const MerchantStatusChangeResponseSchema = dataWrapped(
  z.object({
    id: z.number().int(),
    status: MerchantStatusSchema,
  }),
);
export type MerchantStatusChangeResponse = z.infer<
  typeof MerchantStatusChangeResponseSchema
>;

/**
 * POST /api/admin/merchants/{merchant}/suspend — superadmin-only manual
 * suspension (conduct/fraud), beside the §7 clock's automatic credit
 * control. The reason lands in the append-only notice trail and the store
 * vanishes from the public feed immediately. 409 unless the store is active.
 */
export function suspendAdminMerchant(
  merchantId: number,
  body: { reason: string },
  options: RequestOptions = {},
): Promise<MerchantStatusChangeResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}/suspend`,
    MerchantStatusChangeResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/**
 * POST /api/admin/merchants/{merchant}/reinstate — superadmin-only manual
 * reinstatement, the only path back for a manually suspended store or one
 * whose defaulted debt was written off. 409 unless the store is suspended.
 */
export function reinstateAdminMerchant(
  merchantId: number,
  body: { note: string },
  options: RequestOptions = {},
): Promise<MerchantStatusChangeResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}/reinstate`,
    MerchantStatusChangeResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

export const AdminStaffResetPasswordResponseSchema = z.object({
  data: AdminMerchantStaffSchema,
  /** Returned exactly once — only the hash survives server-side. */
  temp_password: z.string(),
});
export type AdminStaffResetPasswordResponse = z.infer<
  typeof AdminStaffResetPasswordResponseSchema
>;

/**
 * POST /api/admin/merchants/{merchant}/staff/{user}/reset-password —
 * superadmin-only. The server generates a strong temporary password,
 * returns it exactly once, and kills everything the old one unlocked:
 * live panel sessions, every merchant-app token, the remember-me cookie.
 */
export function resetAdminMerchantStaffPassword(
  merchantId: number,
  userId: number,
  options: RequestOptions = {},
): Promise<AdminStaffResetPasswordResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}/staff/${userId}/reset-password`,
    AdminStaffResetPasswordResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

export const AdminMerchantStaffResponseSchema = dataWrapped(
  AdminMerchantStaffSchema,
);
export type AdminMerchantStaffResponse = z.infer<
  typeof AdminMerchantStaffResponseSchema
>;

/**
 * PATCH /api/admin/merchants/{merchant}/staff/{user} — superadmin
 * activate/deactivate. Deactivation destroys the account's app tokens; the
 * last active owner is refused with a 422 (suspend the merchant instead).
 */
export function setAdminMerchantStaffActive(
  merchantId: number,
  userId: number,
  isActive: boolean,
  options: RequestOptions = {},
): Promise<AdminMerchantStaffResponse> {
  return apiFetch(
    `/api/admin/merchants/${merchantId}/staff/${userId}`,
    AdminMerchantStaffResponseSchema,
    { method: "PATCH", body: { is_active: isActive }, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Customer accounts (superadmin support surface)
// ---------------------------------------------------------------------------

/** active | suspended | closed — the customers_status_check literals. */
export const CustomerStatusSchema = z.enum(["active", "suspended", "closed"]);
export type CustomerStatus = z.infer<typeof CustomerStatusSchema>;

export const AdminCustomerRowSchema = z.object({
  id: z.number().int(),
  /** The 6-digit public identity — printed, scanned at the till. */
  customer_code: z.string(),
  name: z.string(),
  /** Full stored E.164 (+960XXXXXXX) — the login identity this surface exists to verify. */
  phone: z.string(),
  status: CustomerStatusSchema,
  kyc_status: z.string(),
  /** Bank + number + name all on file — the payout batch skips them otherwise. */
  has_payout_account: z.boolean(),
  created_at: z.string().nullable(),
});
export type AdminCustomerRow = z.infer<typeof AdminCustomerRowSchema>;

export const AdminCustomerListResponseSchema = paginated(
  AdminCustomerRowSchema,
);
export type AdminCustomerListResponse = z.infer<
  typeof AdminCustomerListResponseSchema
>;

/**
 * GET /api/admin/customers — paginated, newest first. `q` searches name,
 * phone (any typed form — "7712345" folds into the stored +960 shape, and a
 * partial number matches too) and customer code.
 */
export function listAdminCustomers(
  params: { q?: string; page?: number; per_page?: number } = {},
  options: RequestOptions = {},
): Promise<AdminCustomerListResponse> {
  return apiFetch(
    `/api/admin/customers${queryString({
      q: params.q,
      page: params.page,
      per_page: params.per_page,
    })}`,
    AdminCustomerListResponseSchema,
    { signal: options.signal },
  );
}

export const AdminCustomerDetailSchema = z.object({
  id: z.number().int(),
  customer_code: z.string(),
  name: z.string(),
  phone: z.string(),
  /** Null after an admin phone change until the next OTP sign-in re-earns it. */
  phone_verified_at: z.string().nullable(),
  email: z.string().nullable(),
  status: CustomerStatusSchema,
  kyc_status: z.string(),
  avatar_url: z.string().nullable(),
  has_payout_account: z.boolean(),
  /** MASKED account number ("•••• 4821") — the full digits never cross this boundary. */
  payout_account: z
    .object({
      bank: z.string(),
      account_masked: z.string().nullable(),
      account_name: z.string(),
    })
    .nullable(),
  /** The same stored-integer sums the customer's own balance screen shows. */
  balance: z.object({
    currency: z.string(),
    confirmed_laari: z.number().int(),
    pending_laari: z.number().int(),
    paid_this_month_laari: z.number().int(),
  }),
  /** Live app sign-ins (unexpired mobile tokens). */
  devices_count: z.number().int(),
  created_at: z.string().nullable(),
});
export type AdminCustomerDetail = z.infer<typeof AdminCustomerDetailSchema>;

export const AdminCustomerDetailResponseSchema = dataWrapped(
  AdminCustomerDetailSchema,
);
export type AdminCustomerDetailResponse = z.infer<
  typeof AdminCustomerDetailResponseSchema
>;

/** GET /api/admin/customers/{customer} — the full record for the detail drawer. */
export function getAdminCustomer(
  customerId: number,
  options: RequestOptions = {},
): Promise<AdminCustomerDetailResponse> {
  return apiFetch(
    `/api/admin/customers/${customerId}`,
    AdminCustomerDetailResponseSchema,
    { signal: options.signal },
  );
}

export const UpdateAdminCustomerRequestSchema = z.object({
  name: z.string().min(1).max(120).optional(),
  email: z.string().max(255).nullable().optional(),
  /**
   * The sensitive one — the login identity and OTP destination. Accepts any
   * form a person types ("7712345", "+960 771-2345"); the server folds it
   * into the stored +960 shape, refuses anything that is not a Maldivian
   * mobile, and refuses another customer's number. The change deliberately
   * revokes NOTHING: the support scenario is a lost SIM, and the customer's
   * own app install and web session are still theirs.
   */
  phone: z.string().optional(),
});
export type UpdateAdminCustomerRequest = z.infer<
  typeof UpdateAdminCustomerRequestSchema
>;

/**
 * PATCH /api/admin/customers/{customer} — superadmin edit over name, email
 * and phone. Answers the fresh detail record.
 */
export function updateAdminCustomer(
  customerId: number,
  body: UpdateAdminCustomerRequest,
  options: RequestOptions = {},
): Promise<AdminCustomerDetailResponse> {
  return apiFetch(
    `/api/admin/customers/${customerId}`,
    AdminCustomerDetailResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

export const AdminCustomerResetPasswordResponseSchema = z.object({
  data: AdminCustomerDetailSchema,
  /** Returned exactly once — only the hash survives server-side. */
  temp_password: z.string(),
});
export type AdminCustomerResetPasswordResponse = z.infer<
  typeof AdminCustomerResetPasswordResponseSchema
>;

/**
 * POST /api/admin/customers/{customer}/reset-password — superadmin-only.
 * A strong temporary WEB password, returned exactly once. Live web sessions
 * die on their next request; the app deliberately stays signed in — it is
 * passwordless (OTP), so the password never guarded it. Use the status
 * endpoint if the ACCOUNT is in the wrong hands.
 */
export function resetAdminCustomerPassword(
  customerId: number,
  options: RequestOptions = {},
): Promise<AdminCustomerResetPasswordResponse> {
  return apiFetch(
    `/api/admin/customers/${customerId}/reset-password`,
    AdminCustomerResetPasswordResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

export const CustomerStatusChangeResponseSchema = dataWrapped(
  z.object({
    id: z.number().int(),
    status: CustomerStatusSchema,
  }),
);
export type CustomerStatusChangeResponse = z.infer<
  typeof CustomerStatusChangeResponseSchema
>;

/**
 * POST /api/admin/customers/{customer}/status — superadmin enable/disable
 * (active | suspended; `closed` is ledger bookkeeping this endpoint never
 * sets, and a closed account answers 409). Suspending destroys every app
 * token (push registrations cascade with them) and logs any live web
 * session out on its next request; sign-in is refused everywhere until
 * re-enabled.
 */
export function setAdminCustomerStatus(
  customerId: number,
  body: { status: "active" | "suspended"; reason?: string },
  options: RequestOptions = {},
): Promise<CustomerStatusChangeResponse> {
  return apiFetch(
    `/api/admin/customers/${customerId}/status`,
    CustomerStatusChangeResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Reconciliation runs
// ---------------------------------------------------------------------------

export const ReconciliationIssueSchema = z.discriminatedUnion("kind", [
  z.object({
    kind: z.literal("unbalanced_journal"),
    journal_id: z.number().int(),
    currency: z.string(),
    debit_laari: z.number().int(),
    credit_laari: z.number().int(),
  }),
  z.object({
    kind: z.literal("balance_mismatch"),
    account: z.string(),
    derived_laari: z.number().int(),
    ledger_laari: z.number().int(),
  }),
]);
export type ReconciliationIssue = z.infer<typeof ReconciliationIssueSchema>;

export const ReconciliationRunSchema = z.object({
  id: z.number().int(),
  ran_at: z.string(),
  status: z.enum(["ok", "divergent"]),
  journals_checked: z.number().int(),
  issues: z.array(ReconciliationIssueSchema).nullable(),
  /** Per-account derived vs ledger balances, keyed by account code. */
  totals: z.record(
    z.string(),
    z.object({
      derived_laari: z.number().int(),
      ledger_laari: z.number().int(),
    }),
  ),
});
export type ReconciliationRun = z.infer<typeof ReconciliationRunSchema>;

export const ReconciliationRunListResponseSchema = z.object({
  data: z.array(ReconciliationRunSchema),
});
export type ReconciliationRunListResponse = z.infer<
  typeof ReconciliationRunListResponseSchema
>;

/** GET /api/admin/reconciliation — latest runs, newest first (default 30, max 100). */
export function listAdminReconciliationRuns(
  params: { limit?: number } = {},
  options: RequestOptions = {},
): Promise<ReconciliationRunListResponse> {
  return apiFetch(
    `/api/admin/reconciliation${queryString({ limit: params.limit })}`,
    ReconciliationRunListResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Payout batches
// ---------------------------------------------------------------------------

export const PayoutBatchListResponseSchema = paginated(PayoutBatchSchema);
export type PayoutBatchListResponse = z.infer<
  typeof PayoutBatchListResponseSchema
>;

export const PayoutBatchResponseSchema = dataWrapped(PayoutBatchSchema);
export type PayoutBatchResponse = z.infer<typeof PayoutBatchResponseSchema>;

/** GET /api/admin/payout-batches — newest first, 25 per page. */
export function listAdminPayoutBatches(
  params: { page?: number } = {},
  options: RequestOptions = {},
): Promise<PayoutBatchListResponse> {
  return apiFetch(
    `/api/admin/payout-batches${queryString({ page: params.page })}`,
    PayoutBatchListResponseSchema,
    { signal: options.signal },
  );
}

export const CreatePayoutBatchRequestSchema = z.object({
  /**
   * The as-of date, YYYY-MM-DD in business time, so a run can be weekly
   * rather than monthly. Today is the usual answer; a later date is refused
   * (422) because a batch built ahead of its cutoff would miss confirmations
   * still to come.
   */
  cutoff_date: z.string(),
});
export type CreatePayoutBatchRequest = z.infer<
  typeof CreatePayoutBatchRequestSchema
>;

/** POST /api/admin/payout-batches — builds a draft up to the cutoff (201), items loaded. */
export function createAdminPayoutBatch(
  body: CreatePayoutBatchRequest,
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  return apiFetch("/api/admin/payout-batches", PayoutBatchResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

/** GET /api/admin/payout-batches/{batch} — with items. */
export function getAdminPayoutBatch(
  batchId: number,
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  return apiFetch(
    `/api/admin/payout-batches/${batchId}`,
    PayoutBatchResponseSchema,
    { signal: options.signal },
  );
}

/** POST /api/admin/payout-batches/{batch}/approve — draft → approved. */
export function approveAdminPayoutBatch(
  batchId: number,
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  return apiFetch(
    `/api/admin/payout-batches/${batchId}/approve`,
    PayoutBatchResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

/**
 * POST /api/admin/payout-batches/{batch}/send-via-api — the third road to
 * the bank, beside the exported sheet and the per-row Mark paid.
 *
 * Queued: a batch is as many live bank calls as it has rows. The reply says
 * the pass STARTED, which is all a caller can honestly be told about work
 * that has not happened yet.
 */
export function sendAdminPayoutBatchViaApi(
  batchId: number,
  body: { profile_id?: number | null } = {},
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  return apiFetch(
    `/api/admin/payout-batches/${batchId}/send-via-api`,
    PayoutBatchResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/** POST /api/admin/payout-batches/{batch}/cancel — withdraws a draft. */
export function cancelAdminPayoutBatch(
  batchId: number,
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  return apiFetch(
    `/api/admin/payout-batches/${batchId}/cancel`,
    PayoutBatchResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

/**
 * POST /api/admin/payout-batches/{batch}/export — the transfer sheet, .xlsx.
 *
 * Deliberately a POST, not a link target: the first export mutates state
 * (approved → processing, items → sent), so it must never be reachable by a
 * GET a browser could prefetch or a cross-site navigation could trigger.
 * Returns the workbook as a Blob — an xlsx is binary, so it must never go
 * through the text path — for `URL.createObjectURL` and a download named
 * after the batch reference. While the batch is processing and no outcome
 * has been recorded, calling again re-downloads the same sheet.
 */
export function exportAdminPayoutBatch(
  batchId: number,
  options: RequestOptions = {},
): Promise<Blob> {
  return apiFetchBlob(`/api/admin/payout-batches/${batchId}/export`, {
    method: "POST",
    signal: options.signal,
  });
}

/**
 * POST /api/admin/payout-batches/{batch}/import — uploads the filled transfer
 * sheet, .xlsx or the same sheet saved as CSV. Rows whose Transfer Reference
 * Number is still blank are skipped, so a half-filled sheet can be uploaded
 * again as the bank works down it.
 */
export function uploadAdminPayoutSheet(
  batchId: number,
  file: File | Blob,
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  const body = new FormData();
  body.append("file", file);
  return apiFetch(
    `/api/admin/payout-batches/${batchId}/import`,
    PayoutBatchResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/**
 * POST /api/admin/payout-batches/{batch}/items/{item}/mark-paid — records a
 * transfer that went out on its own, against the bank's reference. Refused
 * (422) on an item already paid or failed.
 */
export function markAdminPayoutItemPaid(
  batchId: number,
  itemId: number,
  bankReference: string,
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  return apiFetch(
    `/api/admin/payout-batches/${batchId}/items/${itemId}/mark-paid`,
    PayoutBatchResponseSchema,
    {
      method: "POST",
      body: { bank_reference: bankReference },
      signal: options.signal,
    },
  );
}

/**
 * POST /api/admin/payout-batches/{batch}/items/{item}/mark-failed — the sheet
 * has no failure column on purpose, so a rejected transfer is recorded here.
 * The item's transactions are unlinked and re-enter the next batch.
 */
export function markAdminPayoutItemFailed(
  batchId: number,
  itemId: number,
  failureReason: string,
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  return apiFetch(
    `/api/admin/payout-batches/${batchId}/items/${itemId}/mark-failed`,
    PayoutBatchResponseSchema,
    {
      method: "POST",
      body: { failure_reason: failureReason },
      signal: options.signal,
    },
  );
}

/**
 * POST /api/admin/payout-batches/{batch}/settle-all — one bulk transfer, one
 * reference, applied to every item still waiting. Items already paid or
 * failed are passed over.
 */
export function settleAllAdminPayoutItems(
  batchId: number,
  bankReference: string,
  options: RequestOptions = {},
): Promise<PayoutBatchResponse> {
  return apiFetch(
    `/api/admin/payout-batches/${batchId}/settle-all`,
    PayoutBatchResponseSchema,
    {
      method: "POST",
      body: { bank_reference: bankReference },
      signal: options.signal,
    },
  );
}

// ---------------------------------------------------------------------------
// Claims queue (Phase 3)
// ---------------------------------------------------------------------------

/**
 * A claim as the admin queue sees it: everything the customer view shows,
 * plus who filed it, who resolved it, and the resulting transaction link.
 */
export const AdminClaimSchema = z.object({
  id: z.number().int(),
  state: ClaimStateSchema,
  merchant: z.object({
    id: z.number().int(),
    name: z.string(),
    slug: z.string(),
  }),
  customer: z.object({
    id: z.number().int(),
    customer_code: z.string(),
    name: z.string(),
  }),
  /** Business-timezone date, YYYY-MM-DD. */
  purchased_at: z.string(),
  amount_laari: z.number().int(),
  currency: z.string(),
  receipt_no: z.string(),
  note: z.string().nullable(),
  resolved_by: z.number().int().nullable(),
  resolved_at: z.string().nullable(),
  resolution_note: z.string().nullable(),
  /** The transaction minted by approval (origin 'claim'). */
  resulting_transaction_id: z.number().int().nullable(),
  created_at: z.string(),
});
export type AdminClaim = z.infer<typeof AdminClaimSchema>;

export const AdminClaimListResponseSchema = paginated(AdminClaimSchema);
export type AdminClaimListResponse = z.infer<
  typeof AdminClaimListResponseSchema
>;

export const AdminClaimResponseSchema = dataWrapped(AdminClaimSchema);
export type AdminClaimResponse = z.infer<typeof AdminClaimResponseSchema>;

/** GET /api/admin/claims — oldest first, optionally by state, 25 per page. */
export function listAdminClaims(
  params: { state?: ClaimState; page?: number; per_page?: number } = {},
  options: RequestOptions = {},
): Promise<AdminClaimListResponse> {
  return apiFetch(
    `/api/admin/claims${queryString({
      state: params.state,
      page: params.page,
      per_page: params.per_page,
    })}`,
    AdminClaimListResponseSchema,
    { signal: options.signal },
  );
}

export const ApproveClaimRequestSchema = z.object({
  resolution_note: z.string().max(1000).optional(),
});
export type ApproveClaimRequest = z.infer<typeof ApproveClaimRequestSchema>;

/**
 * POST /api/admin/claims/{id}/approve — mints the missed transaction:
 * origin 'claim', the merchant's rate at the purchase date, normal ceiling
 * money, merchant-funded accrual. 409 when already resolved or the invoice
 * duplicates; 422 when the merchant is inactive, the amount is below
 * minimum, or no rate was effective.
 */
export function approveAdminClaim(
  claimId: number,
  body: ApproveClaimRequest = {},
  options: RequestOptions = {},
): Promise<AdminClaimResponse> {
  return apiFetch(
    `/api/admin/claims/${claimId}/approve`,
    AdminClaimResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

export const RejectClaimRequestSchema = z.object({
  /** Required — becomes the resolution_note the customer sees (§9.4). */
  reason: z.string().min(1).max(1000),
});
export type RejectClaimRequest = z.infer<typeof RejectClaimRequestSchema>;

/** POST /api/admin/claims/{id}/reject — 409 when already resolved. */
export function rejectAdminClaim(
  claimId: number,
  body: RejectClaimRequest,
  options: RequestOptions = {},
): Promise<AdminClaimResponse> {
  return apiFetch(
    `/api/admin/claims/${claimId}/reject`,
    AdminClaimResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Promotions read model (Phase 3)
// ---------------------------------------------------------------------------

/** Custom envelope: plain meta, no links block. */
export const AdminPromotionListResponseSchema = z.object({
  data: z.array(PromotionSchema),
  meta: z.object({
    current_page: z.number().int(),
    last_page: z.number().int(),
    per_page: z.number().int(),
    total: z.number().int(),
  }),
});
export type AdminPromotionListResponse = z.infer<
  typeof AdminPromotionListResponseSchema
>;

/**
 * GET /api/admin/promotions — every merchant's promotions, newest first,
 * filterable by merchant, status, and liveness (published AND the window
 * covering now), 25 per page.
 */
export function listAdminPromotions(
  params: {
    merchant_id?: number;
    status?: PromotionStatus;
    live?: boolean;
    page?: number;
  } = {},
  options: RequestOptions = {},
): Promise<AdminPromotionListResponse> {
  return apiFetch(
    `/api/admin/promotions${queryString({
      merchant_id: params.merchant_id,
      status: params.status,
      live: params.live === undefined ? undefined : params.live ? 1 : 0,
      page: params.page,
    })}`,
    AdminPromotionListResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Platform bank accounts
// ---------------------------------------------------------------------------

/**
 * One of the platform's own bank accounts — where merchants send settlement
 * transfers. There is no DELETE: accounts deactivate, so old settlement
 * instructions stay explicable. Exactly one active primary exists at a time;
 * promoting another demotes the incumbent server-side.
 */
export const PlatformBankAccountSchema = z.object({
  id: z.number().int(),
  bank_name: z.string(),
  account_no: z.string(),
  account_name: z.string(),
  currency: z.string(),
  is_primary: z.boolean(),
  active: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type PlatformBankAccount = z.infer<typeof PlatformBankAccountSchema>;

export const PlatformBankAccountListResponseSchema = z.object({
  data: z.array(PlatformBankAccountSchema),
});
export type PlatformBankAccountListResponse = z.infer<
  typeof PlatformBankAccountListResponseSchema
>;

export const PlatformBankAccountResponseSchema = dataWrapped(
  PlatformBankAccountSchema,
);
export type PlatformBankAccountResponse = z.infer<
  typeof PlatformBankAccountResponseSchema
>;

/** GET /api/admin/platform/bank-accounts — primary first, then by id. */
export function listAdminPlatformBankAccounts(
  options: RequestOptions = {},
): Promise<PlatformBankAccountListResponse> {
  return apiFetch(
    "/api/admin/platform/bank-accounts",
    PlatformBankAccountListResponseSchema,
    { signal: options.signal },
  );
}

export const CreatePlatformBankAccountRequestSchema = z.object({
  bank_name: BankSlugSchema,
  account_no: z.string().min(1).max(255),
  account_name: z.string().min(1).max(255),
  /** MVR only in v1; defaults to MVR when omitted. */
  currency: z.literal("MVR").optional(),
  is_primary: z.boolean().optional(),
  active: z.boolean().optional(),
});
export type CreatePlatformBankAccountRequest = z.infer<
  typeof CreatePlatformBankAccountRequestSchema
>;

/** POST /api/admin/platform/bank-accounts — creates an account (201). */
export function createAdminPlatformBankAccount(
  body: CreatePlatformBankAccountRequest,
  options: RequestOptions = {},
): Promise<PlatformBankAccountResponse> {
  return apiFetch(
    "/api/admin/platform/bank-accounts",
    PlatformBankAccountResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

export const UpdatePlatformBankAccountRequestSchema = z.object({
  bank_name: BankSlugSchema.optional(),
  account_no: z.string().min(1).max(255).optional(),
  account_name: z.string().min(1).max(255).optional(),
  currency: z.literal("MVR").optional(),
  is_primary: z.boolean().optional(),
  active: z.boolean().optional(),
});
export type UpdatePlatformBankAccountRequest = z.infer<
  typeof UpdatePlatformBankAccountRequestSchema
>;

/** PATCH /api/admin/platform/bank-accounts/{id} — partial update. */
export function updateAdminPlatformBankAccount(
  id: number,
  body: UpdatePlatformBankAccountRequest,
  options: RequestOptions = {},
): Promise<PlatformBankAccountResponse> {
  return apiFetch(
    `/api/admin/platform/bank-accounts/${id}`,
    PlatformBankAccountResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Fee tier schedules (§4, admin-manageable, append-only)
// ---------------------------------------------------------------------------

/**
 * One band of the §4 table as the API emits it: cashback range and the fee
 * it carries, all 2-decimal percent strings (PLAN §1 wire format). The
 * table is STORED in integer basis points; the conversion happens at the
 * response boundary, so compare band edges with `percentToBp`, never as
 * text.
 */
export const FeeTierBandSchema = z.object({
  from_percent: PercentSchema,
  to_percent: PercentSchema,
  fee_percent: PercentSchema,
});
export type FeeTierBand = z.infer<typeof FeeTierBandSchema>;

/**
 * One band as a REQUEST may send it: a 2-decimal percent string ("0.50",
 * "10") or a JSON number. The cashback edges obey §4's 0.50%–20.00%; the
 * fee may sit below the cashback floor (the 0.25% first tier).
 */
export const FeeTierBandInputSchema = z.object({
  from_percent: CashbackPercentInputSchema,
  to_percent: CashbackPercentInputSchema,
  fee_percent: FeePercentInputSchema,
});
export type FeeTierBandInput = z.infer<typeof FeeTierBandInputSchema>;

/**
 * One effective-dated §4 tier table. Rows are append-only — never updated
 * or deleted — so every historical instant keeps resolving to the terms it
 * was priced under.
 */
export const FeeTierScheduleSchema = z.object({
  id: z.number().int(),
  effective_from: z.string(),
  tiers: z.array(FeeTierBandSchema),
  created_by: z.number().int().nullable(),
  created_at: z.string(),
});
export type FeeTierSchedule = z.infer<typeof FeeTierScheduleSchema>;

export const FeeTierScheduleIndexResponseSchema = z.object({
  data: z.object({
    /** The schedule active right now; null only before the seed row exists. */
    current: FeeTierScheduleSchema.nullable(),
    /** Full history, newest effective date first (includes future-dated rows). */
    history: z.array(FeeTierScheduleSchema),
  }),
});
export type FeeTierScheduleIndexResponse = z.infer<
  typeof FeeTierScheduleIndexResponseSchema
>;

export const FeeTierScheduleResponseSchema = dataWrapped(FeeTierScheduleSchema);
export type FeeTierScheduleResponse = z.infer<
  typeof FeeTierScheduleResponseSchema
>;

/** GET /api/admin/platform/fee-tiers — the current schedule plus full history. */
export function getAdminFeeTierSchedules(
  options: RequestOptions = {},
): Promise<FeeTierScheduleIndexResponse> {
  return apiFetch(
    "/api/admin/platform/fee-tiers",
    FeeTierScheduleIndexResponseSchema,
    { signal: options.signal },
  );
}

export const CreateFeeTierScheduleRequestSchema = z.object({
  /** ISO 8601 with an explicit UTC offset; must be >= 1 hour in the future. */
  effective_from: z.string(),
  /**
   * Must be ascending, contiguous and gapless, start at exactly 0.50%, end
   * no higher than 20.00% (the schedule's own ceiling — rates above it are
   * refused `rate_not_priced`), with every band's `fee_percent` positive
   * and no greater than its `from_percent`.
   */
  tiers: z.array(FeeTierBandInputSchema).min(1),
});
export type CreateFeeTierScheduleRequest = z.infer<
  typeof CreateFeeTierScheduleRequestSchema
>;

/**
 * POST /api/admin/platform/fee-tiers — publishes a new future-dated schedule
 * (201). 422 when the tier table is invalid or effective_from is less than
 * an hour out.
 */
export function createAdminFeeTierSchedule(
  body: CreateFeeTierScheduleRequest,
  options: RequestOptions = {},
): Promise<FeeTierScheduleResponse> {
  return apiFetch(
    "/api/admin/platform/fee-tiers",
    FeeTierScheduleResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Platform settings
// ---------------------------------------------------------------------------

/**
 * One typed platform setting: the effective value, the hardcoded default it
 * falls back to, and the allowed range.
 *
 * Each key names its own unit, and the unit decides the type:
 * `_laari` and `_days` keys are plain integers in that unit, while a key
 * holding a RATE (`_percent`) is a 2-decimal percent STRING — "5.00",
 * "0.00", "20.00" — because basis points never appear on the wire
 * (PLAN §1 "API wire format"), not even on an admin knob the platform
 * stores in bp. Convert one with `percentToBp` / `bpToPercentString`.
 */
export const PlatformSettingValueSchema = z.union([
  z.number().int(),
  z.string(),
]);

export const PlatformSettingSchema = z.object({
  value: PlatformSettingValueSchema,
  default: PlatformSettingValueSchema,
  min: PlatformSettingValueSchema,
  max: PlatformSettingValueSchema,
  overridden: z.boolean(),
});
export type PlatformSetting = z.infer<typeof PlatformSettingSchema>;

/** The known setting keys; the server is authoritative and may add more. */
export const PlatformSettingKeySchema = z.enum([
  "min_payout_laari",
  "settlement_due_days",
  "write_off_days",
  "default_validation_window_days",
  "default_min_eligible_laari",
  /**
   * PLAN §1 prompt-payment discount: the percent taken off the PLATFORM FEE
   * when a merchant settles everything outstanding promptly ("0.00" disables
   * it, "20.00" is the ceiling), and how young every line must be, in whole
   * days, to qualify. The window must stay shorter than
   * `settlement_due_days` or the incentive rewards nothing; the range (1–15)
   * enforces the outer bound.
   */
  "prompt_discount_rate_percent",
  "prompt_discount_max_age_days",
  /**
   * The window a NEW store is created with, as distinct from the ceiling it
   * may raise itself to (`default_validation_window_days`).
   */
  "new_merchant_validation_window_days",
  /**
   * MARKETPLACE (PLAN-marketplace.md §10). `marketplace_enabled` is a
   * BOOLEAN carried as 0/1 — the settings table stores integers, and the
   * panel renders it as a switch rather than a number box, because "how many
   * marketplaces" is not a question. `marketplace_fee_percent` is a rate and
   * follows the same 2-decimal string wire format as the other percent key.
   */
  "marketplace_enabled",
  "marketplace_fee_percent",
  /**
   * REFERRALS (owner, 2026-08-23). `referral_enabled` is a BOOLEAN carried
   * as 0/1, same convention as `marketplace_enabled` — render it as a
   * switch. The other two are integer laari: the bonus credited to the
   * referrer's wallet, and the cumulative validated spend a referred
   * customer must reach to trigger it. All three PATCH as SUPERADMIN-only
   * (403 otherwise) — they direct platform money into customer wallets and
   * a paid bonus has no clawback.
   */
  "referral_enabled",
  "referral_reward_laari",
  "referral_spend_threshold_laari",
  /**
   * WALLET TOP-UPS (owner, 2026-08-24). The smallest bank transfer a
   * merchant may claim into their wallet, integer laari — 10000 (MVR 100)
   * by default, range 100–100000000. The merchant wallet payload reports it
   * as `top_up_min_laari`. PATCHes as SUPERADMIN-only (403 otherwise): it
   * shapes how much merchant money the platform holds in advance.
   */
  "wallet_top_up_min_laari",
]);
export type PlatformSettingKey = z.infer<typeof PlatformSettingKeySchema>;

export const PlatformSettingsResponseSchema = z.object({
  data: z.record(z.string(), PlatformSettingSchema),
});
export type PlatformSettingsResponse = z.infer<
  typeof PlatformSettingsResponseSchema
>;

/** GET /api/admin/platform/settings — every key with value, default and range. */
export function getAdminPlatformSettings(
  options: RequestOptions = {},
): Promise<PlatformSettingsResponse> {
  return apiFetch(
    "/api/admin/platform/settings",
    PlatformSettingsResponseSchema,
    { signal: options.signal },
  );
}

export const UpdatePlatformSettingRequestSchema = z.object({
  /**
   * The key's own unit: an integer for `_laari` / `_days` keys, a 2-decimal
   * percent for a `_percent` key ("7.5", "7.50" or the number 7.5 — never
   * basis points). Validated server-side against the allowed range (422).
   */
  value: PlatformSettingValueSchema,
});
export type UpdatePlatformSettingRequest = z.infer<
  typeof UpdatePlatformSettingRequestSchema
>;

/**
 * PATCH /api/admin/platform/settings/{key} — writes one key. Returns just
 * that key's refreshed entry. 404 on an unknown key, 422 out of range.
 */
export function updateAdminPlatformSetting(
  key: PlatformSettingKey,
  body: UpdatePlatformSettingRequest,
  options: RequestOptions = {},
): Promise<PlatformSettingsResponse> {
  return apiFetch(
    `/api/admin/platform/settings/${encodeURIComponent(key)}`,
    PlatformSettingsResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// App release gates
// ---------------------------------------------------------------------------

/**
 * The release gate for one app on one platform: the oldest build the API
 * keeps serving, the newest build available, and where an out-of-date
 * install is sent. Served to the apps by the public /api/mobile/v1/config;
 * this admin surface edits what that endpoint answers.
 */
export const AppReleaseFlagsSchema = z.object({
  minimum_build: z.number().int(),
  latest_build: z.number().int(),
  store_url: z.string().nullable(),
});
export type AppReleaseFlags = z.infer<typeof AppReleaseFlagsSchema>;

/**
 * app ("customer", "merchant", …) => platform ("ios" | "android") => flags.
 * The server derives the app list from its config, so new apps appear here
 * without a client change.
 */
export const AppReleasesSchema = z.record(
  z.string(),
  z.record(z.string(), AppReleaseFlagsSchema),
);
export type AppReleases = z.infer<typeof AppReleasesSchema>;

export const AppReleasesResponseSchema = z.object({
  data: AppReleasesSchema,
});
export type AppReleasesResponse = z.infer<typeof AppReleasesResponseSchema>;

/** GET /api/admin/platform/app-releases — effective flags (override or env default). */
export function getAdminAppReleases(
  options: RequestOptions = {},
): Promise<AppReleasesResponse> {
  return apiFetch(
    "/api/admin/platform/app-releases",
    AppReleasesResponseSchema,
    {
      signal: options.signal,
    },
  );
}

/**
 * PUT /api/admin/platform/app-releases — writes the FULL flag set (every
 * app, both platforms) and answers it back. 422 when a latest build sits
 * below its minimum, a build is not a positive integer, or a store URL is
 * not a URL.
 */
export function updateAdminAppReleases(
  body: AppReleases,
  options: RequestOptions = {},
): Promise<AppReleasesResponse> {
  return apiFetch(
    "/api/admin/platform/app-releases",
    AppReleasesResponseSchema,
    {
      method: "PUT",
      body,
      signal: options.signal,
    },
  );
}

// ---------------------------------------------------------------------------
// Admin users (superadmin only)
// ---------------------------------------------------------------------------

export const AdminRoleSchema = z.enum(["admin", "superadmin"]);
export type AdminRole = z.infer<typeof AdminRoleSchema>;

/**
 * An admin account as the superadmin management screen sees it. No DELETE
 * exists — deactivation is the only removal, so audit columns referencing an
 * admin keep resolving.
 */
export const AdminUserAccountSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
  role: AdminRoleSchema,
  is_active: z.boolean(),
  created_at: z.string().nullable(),
});
export type AdminUserAccount = z.infer<typeof AdminUserAccountSchema>;

export const AdminUserListResponseSchema = z.object({
  data: z.array(AdminUserAccountSchema),
});
export type AdminUserListResponse = z.infer<typeof AdminUserListResponseSchema>;

export const AdminUserResponseSchema = dataWrapped(AdminUserAccountSchema);
export type AdminUserResponse = z.infer<typeof AdminUserResponseSchema>;

/** GET /api/admin/admins — all admin accounts, by id. Superadmin only (403). */
export function listAdminUsers(
  options: RequestOptions = {},
): Promise<AdminUserListResponse> {
  return apiFetch("/api/admin/admins", AdminUserListResponseSchema, {
    signal: options.signal,
  });
}

export const CreateAdminUserRequestSchema = z.object({
  name: z.string().min(1).max(255),
  email: z.string().min(1).max(255),
  /** Defaults to 'admin' when omitted. */
  role: AdminRoleSchema.optional(),
});
export type CreateAdminUserRequest = z.infer<
  typeof CreateAdminUserRequestSchema
>;

/** Creation response: the account plus the one-time temporary password. */
export const CreateAdminUserResponseSchema = z.object({
  data: AdminUserAccountSchema,
  /** Shown exactly once, on creation — only the hash survives server-side. */
  temp_password: z.string(),
});
export type CreateAdminUserResponse = z.infer<
  typeof CreateAdminUserResponseSchema
>;

/** POST /api/admin/admins — creates an admin account (201). Superadmin only. */
export function createAdminUser(
  body: CreateAdminUserRequest,
  options: RequestOptions = {},
): Promise<CreateAdminUserResponse> {
  return apiFetch("/api/admin/admins", CreateAdminUserResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

export const UpdateAdminUserRequestSchema = z.object({
  role: AdminRoleSchema.optional(),
  is_active: z.boolean().optional(),
});
export type UpdateAdminUserRequest = z.infer<
  typeof UpdateAdminUserRequestSchema
>;

/**
 * PATCH /api/admin/admins/{id} — changes role and/or active flag. Superadmin
 * only. 422 when the change would demote or deactivate the last active
 * superadmin (or yourself).
 */
export function updateAdminUser(
  id: number,
  body: UpdateAdminUserRequest,
  options: RequestOptions = {},
): Promise<AdminUserResponse> {
  return apiFetch(`/api/admin/admins/${id}`, AdminUserResponseSchema, {
    method: "PATCH",
    body,
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Island zoning
// ---------------------------------------------------------------------------

export const ZonePointSchema = z.object({
  lat: z.number(),
  lng: z.number(),
});
export type ZonePoint = z.infer<typeof ZonePointSchema>;

/**
 * One island zone: a closed polygon ring drawn by an admin. Every merchant
 * branch whose pin falls inside is assigned server-side on every zone write
 * — `branch_count` is that assignment, read back.
 */
export const ZoneSchema = z.object({
  id: z.number(),
  name: z.string(),
  name_dv: z.string().nullable(),
  polygon: z.array(ZonePointSchema),
  branch_count: z.number(),
  /** Position in the admin-arranged list (added order until edited). */
  sort_order: z.number(),
});
export type Zone = z.infer<typeof ZoneSchema>;

export const ZoneListResponseSchema = z.object({
  data: z.array(ZoneSchema),
});
export type ZoneListResponse = z.infer<typeof ZoneListResponseSchema>;

export const ZoneResponseSchema = dataWrapped(ZoneSchema);
export type ZoneResponse = z.infer<typeof ZoneResponseSchema>;

export interface ZoneInput {
  /** Required and non-empty — the client resolves a name BEFORE submitting. */
  name: string;
  name_dv?: string | null;
  /** The ring's vertices in order, 3 to 500 points. */
  polygon: ZonePoint[];
}

/** GET /api/admin/zones — every zone in the arranged order, with branch counts. */
export function listZones(
  options: RequestOptions = {},
): Promise<ZoneListResponse> {
  return apiFetch("/api/admin/zones", ZoneListResponseSchema, {
    signal: options.signal,
  });
}

/**
 * PUT /api/admin/zones/order — the COMPLETE id list in display order. The
 * app's island picker mirrors this arrangement. 422 unless every zone
 * appears exactly once. Answers the reordered list.
 */
export function reorderZones(
  ids: number[],
  options: RequestOptions = {},
): Promise<ZoneListResponse> {
  return apiFetch("/api/admin/zones/order", ZoneListResponseSchema, {
    method: "PUT",
    body: { ids },
    signal: options.signal,
  });
}

/** POST /api/admin/zones — 201; reassigns every pinned branch. */
export function createZone(
  body: ZoneInput,
  options: RequestOptions = {},
): Promise<ZoneResponse> {
  return apiFetch("/api/admin/zones", ZoneResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

/** PUT /api/admin/zones/{id} — full replace; reassigns every pinned branch. */
export function updateZone(
  id: number,
  body: ZoneInput,
  options: RequestOptions = {},
): Promise<ZoneResponse> {
  return apiFetch(`/api/admin/zones/${id}`, ZoneResponseSchema, {
    method: "PUT",
    body,
    signal: options.signal,
  });
}

/**
 * DELETE /api/admin/zones/{id} — 204. Branches inside the deleted zone are
 * released and re-offered to the remaining zones where polygons overlap.
 */
export async function deleteZone(
  id: number,
  options: RequestOptions = {},
): Promise<void> {
  await apiFetch(`/api/admin/zones/${id}`, z.undefined(), {
    method: "DELETE",
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Storefront brand colour (superadmin appearance lever)
// ---------------------------------------------------------------------------

export const BrandColorResponseSchema = z.object({
  data: z.object({ color: z.string().nullable() }),
});
export type BrandColorResponse = z.infer<typeof BrandColorResponseSchema>;

/** GET /api/admin/platform/brand — null until a colour has been chosen. */
export function getAdminBrandColor(
  options: RequestOptions = {},
): Promise<BrandColorResponse> {
  return apiFetch("/api/admin/platform/brand", BrandColorResponseSchema, {
    signal: options.signal,
  });
}

/**
 * PUT /api/admin/platform/brand — superadmin. `#rrggbb` sets the storefront
 * accent; null clears it back to the built-in hue.
 */
export function setAdminBrandColor(
  color: string | null,
  options: RequestOptions = {},
): Promise<BrandColorResponse> {
  return apiFetch("/api/admin/platform/brand", BrandColorResponseSchema, {
    method: "PUT",
    body: { color },
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// MARKETPLACE OPERATIONS (PLAN-marketplace.md §5.5, §9, §21)
// ---------------------------------------------------------------------------

/**
 * Money owed to a customer, waiting to leave. Worked by hand today; a worker
 * drains exactly these rows once the bank tunnel exists.
 */
export const PendingPaymentSchema = z.object({
  id: z.number().int(),
  customer_name: z.string().nullable(),
  customer_phone: z.string().nullable(),
  amount_laari: z.number().int(),
  bank: z.string().nullable(),
  account: z.string(),
  account_name: z.string().nullable(),
  /** The bank's idempotency key. The same string identifies it everywhere. */
  internal_ref: z.string(),
  state: z.enum([
    "pending",
    "processing",
    "pending_approval",
    "sent",
    "failed",
    "cancelled",
  ]),
  attempts: z.number().int(),
  trx_id: z.string().nullable(),
  /**
   * An approvals-QUEUE record id, not a bank reference. Never shown as one:
   * quoting it at a bank gets nowhere.
   */
  approval_id: z.string().nullable(),
  error_code: z.string().nullable(),
  failure_reason: z.string().nullable(),
  requested_at: z.string().nullable(),
});
export type PendingPayment = z.infer<typeof PendingPaymentSchema>;

export const PendingPaymentsResponseSchema = z.object({
  data: z.array(PendingPaymentSchema),
  meta: z.object({
    counts: z.record(z.string(), z.number()).catch({}),
    auto_transfer_enabled: z.boolean().catch(false),
  }),
});

export function listPendingPayments(
  state = "pending",
  options: RequestOptions = {},
) {
  return apiFetch(
    `/api/admin/pending-payments?state=${encodeURIComponent(state)}`,
    PendingPaymentsResponseSchema,
    { signal: options.signal },
  );
}

const PayoutActionSchema = dataWrapped(
  z.object({
    id: z.number().int().optional(),
    state: z.string(),
    trx_id: z.string().nullable().optional(),
    approval_id: z.string().nullable().optional(),
    error_code: z.string().nullable().optional(),
    failure_reason: z.string().nullable().optional(),
    /**
     * The transfer is QUEUED, not done. A transfer can take two minutes and
     * the web server hangs up long before that, so the outcome arrives on
     * the row rather than in this reply.
     */
    queued: z.boolean().optional(),
  }),
);

export function sendPendingPayment(id: number, profileId?: number) {
  return apiFetch(
    `/api/admin/pending-payments/${id}/send`,
    PayoutActionSchema,
    {
      method: "POST",
      body: profileId === undefined ? {} : { profile_id: profileId },
    },
  );
}

/** Record a transfer made by hand — every transfer, until the tunnel exists. */
export function markPendingPaymentSent(id: number, trxId: string) {
  return apiFetch(
    `/api/admin/pending-payments/${id}/mark-sent`,
    PayoutActionSchema,
    { method: "POST", body: { trx_id: trxId } },
  );
}

export function cancelPendingPayment(id: number, reason: string) {
  return apiFetch(
    `/api/admin/pending-payments/${id}/cancel`,
    PayoutActionSchema,
    { method: "POST", body: { reason } },
  );
}

// --------------------------------------------------------- transfer settings

export const TransferProfileSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  base_url: z.string(),
  segment: z.string(),
  from_account: z.string().nullable(),
  /**
   * BML is a different upstream, not a variant of MIB. It identifies the
   * account by a profile NAME on the wire.
   */
  is_bml: z.boolean(),
  upstream_profile: z.string().nullable(),
  /**
   * Read-only upstream: it can have its history watched but is never sent
   * from. Every payout leaves from MIB, whatever bank the payee uses.
   */
  history_only: z.boolean(),
  endpoint: z.string(),
  /** Answers 200 `pending_approval`: accepted and parked, never re-sent. */
  dual_control: z.boolean(),
  active: z.boolean(),
  is_default: z.boolean(),
});
export type TransferProfile = z.infer<typeof TransferProfileSchema>;

/**
 * One of OUR accounts a customer can be told to pay into, and the profile
 * that reads its history.
 *
 * There is no single global "watched account": customers choose their bank
 * at checkout, so one account could only ever verify half the orders.
 */
export const WatchedAccountSchema = z.object({
  id: z.number().int(),
  bank_name: z.string(),
  account_no: z.string(),
  account_name: z.string().nullable(),
  active: z.boolean(),
  is_primary: z.boolean(),
  /** Null means nobody watches it and a person verifies by hand. */
  verify_profile_id: z.number().int().nullable(),
});
export type WatchedAccount = z.infer<typeof WatchedAccountSchema>;

export const TransferSettingsResponseSchema = dataWrapped(
  z.object({
    auto_transfer_enabled: z.boolean(),
    auto_max_laari: z.number().int(),
    profile_id: z.number().int().nullable(),
    /** Whether a key is SET. Never the key — it is the whole of the auth. */
    api_key_configured: z.boolean(),
    /** Auto-matching a customer payment to an order, behind its own flag. */
    auto_verify_enabled: z.boolean(),
    verify_window_minutes: z.number().int(),
    verify_min_score: z.number().int(),
    profiles: z.array(TransferProfileSchema),
    watched_accounts: z.array(WatchedAccountSchema),
  }),
);
export type TransferSettingsResponse = z.infer<
  typeof TransferSettingsResponseSchema
>;

export function getTransferSettings(options: RequestOptions = {}) {
  return apiFetch(
    "/api/admin/transfer-settings",
    TransferSettingsResponseSchema,
    {
      signal: options.signal,
    },
  );
}

export function updateTransferSettings(body: {
  auto_transfer_enabled?: boolean;
  auto_max_laari?: number;
  profile_id?: number | null;
  auto_verify_enabled?: boolean;
  verify_window_minutes?: number;
  verify_min_score?: number;
}) {
  return apiFetch(
    "/api/admin/transfer-settings",
    TransferSettingsResponseSchema,
    {
      method: "PATCH",
      body,
    },
  );
}

export function updateTransferProfile(
  id: number,
  body: {
    name?: string;
    base_url?: string;
    segment?: string;
    from_account?: string | null;
    upstream_profile?: string | null;
    dual_control?: boolean;
    active?: boolean;
    is_default?: boolean;
  },
) {
  return apiFetch(
    `/api/admin/transfer-settings/profiles/${id}`,
    TransferSettingsResponseSchema,
    { method: "PATCH", body },
  );
}

/** Point one of our accounts at the profile that reads its history. */
export function updateWatchedAccount(
  id: number,
  body: { verify_profile_id: number | null },
) {
  return apiFetch(
    `/api/admin/transfer-settings/watched-accounts/${id}`,
    TransferSettingsResponseSchema,
    { method: "PATCH", body },
  );
}

// ------------------------------------------------------ merchant settlements

export const MerchantSettlementBatchSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  state: z.enum(["draft", "approved", "processing", "completed", "cancelled"]),
  total_laari: z.number().int(),
  merchant_count: z.number().int(),
  /** Money held back for want of bank details — surfaced, never silent. */
  excluded_laari: z.number().int(),
  excluded_count: z.number().int(),
  cutoff_at: z.string().nullable(),
  approved_at: z.string().nullable(),
  exported_at: z.string().nullable(),
  /** Set when the run went out through the bank API rather than as a sheet. */
  api_sent_at: z.string().nullable().optional(),
});
export type MerchantSettlementBatch = z.infer<
  typeof MerchantSettlementBatchSchema
>;

export const MerchantSettlementsResponseSchema = z.object({
  data: z.array(MerchantSettlementBatchSchema),
  meta: z.object({ payable_now_laari: z.number().int().catch(0) }),
});

export function listMerchantPayoutBatches(options: RequestOptions = {}) {
  return apiFetch(
    "/api/admin/merchant-settlements",
    MerchantSettlementsResponseSchema,
    { signal: options.signal },
  );
}

export const MerchantSettlementItemSchema = z.object({
  id: z.number().int(),
  merchant_name: z.string(),
  amount_laari: z.number().int(),
  bank: z.string().nullable(),
  account: z.string(),
  account_name: z.string().nullable(),
  internal_ref: z.string(),
  state: z.string(),
  trx_id: z.string().nullable(),
  approval_id: z.string().nullable(),
  failure_reason: z.string().nullable(),
  order_count: z.number().int(),
});
export type MerchantSettlementItem = z.infer<
  typeof MerchantSettlementItemSchema
>;

export const MerchantSettlementDetailSchema = dataWrapped(
  z.object({
    id: z.number().int(),
    reference: z.string(),
    state: z.string(),
    total_laari: z.number().int(),
    items: z.array(MerchantSettlementItemSchema),
  }),
);

export function getMerchantPayoutBatch(
  id: number,
  options: RequestOptions = {},
) {
  return apiFetch(
    `/api/admin/merchant-settlements/${id}`,
    MerchantSettlementDetailSchema,
    { signal: options.signal },
  );
}

export function buildMerchantPayoutBatch() {
  return apiFetch(
    "/api/admin/merchant-settlements",
    dataWrapped(z.object({ id: z.number().int(), reference: z.string() })),
    { method: "POST", body: {} },
  );
}

export function approveMerchantPayoutBatch(id: number) {
  return apiFetch(
    `/api/admin/merchant-settlements/${id}/approve`,
    dataWrapped(z.object({ state: z.string() })),
    { method: "POST", body: {} },
  );
}

export function cancelMerchantPayoutBatch(id: number) {
  return apiFetch(
    `/api/admin/merchant-settlements/${id}/cancel`,
    dataWrapped(z.object({ state: z.string() })),
    { method: "POST", body: {} },
  );
}

/** POST /api/admin/merchant-settlements/{batch}/send — the whole run, queued. */
export function sendMerchantPayoutBatchViaApi(
  batchId: number,
  body: { profile_id?: number | null } = {},
) {
  return apiFetch(
    `/api/admin/merchant-settlements/${batchId}/send`,
    dataWrapped(z.object({ queued: z.number().int() })),
    { method: "POST", body },
  );
}

export function sendMerchantPayoutItem(batchId: number, itemId: number) {
  return apiFetch(
    `/api/admin/merchant-settlements/${batchId}/items/${itemId}/send`,
    dataWrapped(
      z.object({
        state: z.string(),
        trx_id: z.string().nullable(),
        approval_id: z.string().nullable(),
        /** The transfer is queued, not done — the row carries the outcome. */
        queued: z.boolean().optional(),
      }),
    ),
    { method: "POST", body: {} },
  );
}

// ------------------------------------------------------------- marketplace KYB

export const KybApplicationSchema = z.object({
  merchant_id: z.number().int(),
  merchant_name: z.string().nullable(),
  merchant_slug: z.string().nullable(),
  merchant_status: z.string().nullable(),
  contact_phone: z.string().nullable(),
  state: z.enum([
    "not_enrolled",
    "pending_kyb",
    "active",
    "rejected",
    "suspended",
  ]),
  business_type: z.string().nullable(),
  fulfilment: z.string().nullable(),
  prep_time_min: z.number().int().nullable(),
  prep_time_max: z.number().int().nullable(),
  rejected_reason: z.string().nullable(),
  submitted_at: z.string().nullable(),
  approved_at: z.string().nullable(),
});
export type KybApplication = z.infer<typeof KybApplicationSchema>;

export function listKybApplications(
  state = "pending_kyb",
  options: RequestOptions = {},
) {
  return apiFetch(
    `/api/admin/marketplace/kyb?state=${encodeURIComponent(state)}`,
    z.object({ data: z.array(KybApplicationSchema) }),
    { signal: options.signal },
  );
}

export const KybDocumentSchema = z.object({
  id: z.number().int(),
  kind: z.string(),
  original_name: z.string(),
  mime: z.string().optional(),
  size: z.number().int(),
  state: z.string(),
  reject_reason: z.string().nullable(),
  uploaded_at: z.string().nullable(),
});

export function getKybApplication(
  merchantId: number,
  options: RequestOptions = {},
) {
  return apiFetch(
    `/api/admin/marketplace/kyb/${merchantId}`,
    dataWrapped(
      KybApplicationSchema.extend({
        documents: z.array(KybDocumentSchema),
        missing_documents: z.array(z.string()),
      }),
    ),
    { signal: options.signal },
  );
}

export function approveKyb(merchantId: number) {
  return apiFetch(
    `/api/admin/marketplace/kyb/${merchantId}/approve`,
    dataWrapped(KybApplicationSchema),
    { method: "POST", body: {} },
  );
}

export function rejectKyb(merchantId: number, reason: string) {
  return apiFetch(
    `/api/admin/marketplace/kyb/${merchantId}/reject`,
    dataWrapped(KybApplicationSchema),
    { method: "POST", body: { reason } },
  );
}

// --------------------------------------------------------- order payments

export const OrderPaymentSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  customer_name: z.string().nullable(),
  customer_phone: z.string().nullable(),
  total_payable_laari: z.number().int(),
  payment_method: z.string(),
  payment_state: z.enum([
    "awaiting_proof",
    "proof_submitted",
    "verified",
    "refused",
  ]),
  has_receipt: z.boolean(),
  proof_submitted_at: z.string().nullable(),
  /**
   * Verified by the bank-history matcher rather than by a person.
   * `verified_by` stays null for these on purpose — no admin decided it —
   * so this flag is the only thing that records who did.
   */
  auto_verified: z.boolean(),
  matched_trx_id: z.string().nullable(),
  matched_payer_name: z.string().nullable(),
  matched_score: z.number().int().nullable(),
  /** Set while the bank is still being watched for this payment. */
  poll_until: z.string().nullable(),
  stores: z.array(z.string().nullable()),
});
export type OrderPayment = z.infer<typeof OrderPaymentSchema>;

export function listOrderPayments(
  state = "proof_submitted",
  options: RequestOptions = {},
) {
  return apiFetch(
    `/api/admin/marketplace/payments?payment_state=${encodeURIComponent(state)}`,
    z.object({ data: z.array(OrderPaymentSchema) }),
    { signal: options.signal },
  );
}

export function verifyOrderPayment(orderId: number) {
  return apiFetch(
    `/api/admin/marketplace/payments/${orderId}/verify`,
    dataWrapped(z.object({ payment_state: z.string() })),
    { method: "POST", body: {} },
  );
}

export function refuseOrderPayment(orderId: number, reason: string) {
  return apiFetch(
    `/api/admin/marketplace/payments/${orderId}/refuse`,
    dataWrapped(
      z.object({ payment_state: z.string(), refused_reason: z.string() }),
    ),
    { method: "POST", body: { reason } },
  );
}

// ---------------------------------------------------------------------------
// Platform clients — "IsleBooks would like to …" (superadmin only)
// ---------------------------------------------------------------------------

/**
 * A registered platform: software that may ask ANY merchant on Manfaa for
 * access, on a consent screen of ours. The merchant still decides, but the
 * asking is the privilege, which is why only a superadmin registers one.
 *
 * A developer without a registration is not blocked — they use the
 * per-merchant key, which the merchant issues themselves and which reaches
 * exactly one shop.
 */
export const PlatformClientSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  display_name: z.string().nullable(),
  description: z.string().nullable(),
  website: z.string().nullable(),
  contact: z.string().nullable(),
  /** Public identifier; travels in the authorize URL. */
  client_id: z.string().nullable(),
  /**
   * A plugin on merchants' own servers: no secret (PKCE is its proof) and
   * no callback list (the callback arrives with each request and the
   * merchant's consent is the registration). Fixed at registration.
   */
  public_client: z.boolean(),
  /** The secret itself is never readable after issuance — only this flag. */
  has_secret: z.boolean(),
  redirect_uris: z.array(z.string()),
  /** The ceiling: what this platform may ASK a merchant for. */
  allowed_abilities: z.array(z.string()),
  connect_enabled: z.boolean(),
  integration_status: z.string().nullable(),
  /** Live grants across all merchants. */
  connections: z.number().int(),
});
export type PlatformClient = z.infer<typeof PlatformClientSchema>;

export const ConnectAbilityInfoSchema = z.object({
  ability: z.string(),
  /** The sentence the shopkeeper reads. */
  consent_line: z.string(),
  /** The warning under it, where one is warranted. */
  caution: z.string().nullable(),
});
export type ConnectAbilityInfo = z.infer<typeof ConnectAbilityInfoSchema>;

export const PlatformClientListResponseSchema = z.object({
  data: z.array(PlatformClientSchema),
  meta: z.object({ abilities: z.array(ConnectAbilityInfoSchema) }),
});
export type PlatformClientListResponse = z.infer<
  typeof PlatformClientListResponseSchema
>;

/** Registration and rotation both hand back the secret — once. */
export const PlatformClientSecretResponseSchema = z.object({
  data: PlatformClientSchema.extend({
    /** Null for a public client — there is nothing to hand over. */
    client_secret: z.string().nullable(),
    connections_revoked: z.number().int().optional(),
  }),
});
export type PlatformClientSecretResponse = z.infer<
  typeof PlatformClientSecretResponseSchema
>;

export const PlatformClientResponseSchema = dataWrapped(PlatformClientSchema);
export type PlatformClientResponse = z.infer<
  typeof PlatformClientResponseSchema
>;

/** GET /api/admin/platform-clients — every registration. Superadmin only. */
export function listPlatformClients(
  options: RequestOptions = {},
): Promise<PlatformClientListResponse> {
  return apiFetch(
    "/api/admin/platform-clients",
    PlatformClientListResponseSchema,
    {
      signal: options.signal,
    },
  );
}

export const PlatformClientWriteSchema = z.object({
  name: z.string().min(1).max(120),
  display_name: z.string().max(120).nullish(),
  description: z.string().max(500).nullish(),
  /** HTTPS only — an authorization code must never cross the wire in clear. */
  website: z.string().max(255).nullish(),
  contact: z.string().max(255).nullish(),
  /** Required for a confidential platform; omitted for a public client. */
  redirect_uris: z.array(z.string()).max(10).optional(),
  allowed_abilities: z.array(z.string()).min(1),
  connect_enabled: z.boolean().optional(),
  /** Create-only. */
  public_client: z.boolean().optional(),
});
export type PlatformClientWrite = z.infer<typeof PlatformClientWriteSchema>;

/** POST /api/admin/platform-clients — registers a platform (201). */
export function createPlatformClient(
  body: PlatformClientWrite,
  options: RequestOptions = {},
): Promise<PlatformClientSecretResponse> {
  return apiFetch(
    "/api/admin/platform-clients",
    PlatformClientSecretResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/** PATCH /api/admin/platform-clients/{id} — everything but the secret. */
export function updatePlatformClient(
  id: number,
  body: Partial<PlatformClientWrite> & { integration_status?: string },
  options: RequestOptions = {},
): Promise<PlatformClientResponse> {
  return apiFetch(
    `/api/admin/platform-clients/${id}`,
    PlatformClientResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

/**
 * POST /api/admin/platform-clients/{id}/rotate — a new secret, and every
 * token the old one produced is cut. Rotation happens because a secret
 * leaked; leaving the grants alive would mean rotating changed nothing.
 */
export function rotatePlatformClientSecret(
  id: number,
  options: RequestOptions = {},
): Promise<PlatformClientSecretResponse> {
  return apiFetch(
    `/api/admin/platform-clients/${id}/rotate`,
    PlatformClientSecretResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Brand marks (superadmin only)
// ---------------------------------------------------------------------------

/**
 * One of the platform's five brand slots. `url` ALWAYS renders — it serves
 * the packaged default until a superadmin uploads something — which is why
 * every surface can point an <img> at it with no fallback logic.
 */
export const BrandAssetSlotSchema = z.enum([
  "landscape_light",
  "landscape_dark",
  "square_light",
  "square_dark",
  "favicon",
]);
export type BrandAssetSlot = z.infer<typeof BrandAssetSlotSchema>;

export const BrandAssetSchema = z.object({
  slot: BrandAssetSlotSchema,
  label: z.string(),
  shape: z.enum(["landscape", "square", "favicon"]),
  url: z.string(),
  /** False means the packaged default is showing. */
  is_custom: z.boolean(),
  original_name: z.string().nullable(),
  updated_at: z.string().nullable(),
  updated_by: z.string().nullable(),
});
export type BrandAsset = z.infer<typeof BrandAssetSchema>;

export const BrandAssetListResponseSchema = z.object({
  data: z.array(BrandAssetSchema),
});
export type BrandAssetListResponse = z.infer<
  typeof BrandAssetListResponseSchema
>;

/** GET /api/admin/brand — all five slots. Superadmin only. */
export function listBrandAssets(
  options: RequestOptions = {},
): Promise<BrandAssetListResponse> {
  return apiFetch("/api/admin/brand", BrandAssetListResponseSchema, {
    signal: options.signal,
  });
}

/**
 * POST /api/admin/brand/{slot} — replaces one mark. SVG is refused for every
 * slot: it is a document that may carry script, and it would be served from
 * our own origin on every surface.
 */
export function uploadBrandAsset(
  slot: BrandAssetSlot,
  file: File,
): Promise<BrandAssetListResponse> {
  const body = new FormData();
  body.append("file", file);

  return apiFetch(`/api/admin/brand/${slot}`, BrandAssetListResponseSchema, {
    method: "POST",
    body,
  });
}

/** DELETE /api/admin/brand/{slot} — back to the packaged default. */
export function resetBrandAsset(
  slot: BrandAssetSlot,
): Promise<BrandAssetListResponse> {
  return apiFetch(`/api/admin/brand/${slot}`, BrandAssetListResponseSchema, {
    method: "DELETE",
  });
}

// ---------------------------------------------------------------------------
// Vendor-owned webhook endpoints (the superadmin registry; owner, 2026-08-22)
// ---------------------------------------------------------------------------

/**
 * One endpoint a POS PLATFORM owns: it receives every connected merchant's
 * events for that platform (each event carries `merchant_id`). Registered
 * here by a superadmin; the signing secret is shown exactly once.
 */
export const VendorWebhookEndpointSchema = z.object({
  id: z.number().int(),
  pos_vendor_id: z.number().int(),
  url: z.string(),
  events: z.array(z.string()),
  active: z.boolean(),
  last_delivery: z
    .object({
      event: z.string(),
      status: z.string(),
      response_status: z.number().int().nullable(),
      attempted_at: z.string().nullable(),
    })
    .nullable()
    .optional()
    .default(null),
  created_at: z.string().nullable(),
});
export type VendorWebhookEndpoint = z.infer<typeof VendorWebhookEndpointSchema>;

export const VendorWebhookEndpointListResponseSchema = z.object({
  data: z.array(VendorWebhookEndpointSchema),
});

export const CreateVendorWebhookEndpointResponseSchema = z.object({
  /** Shown once; there is no retrieval path. */
  secret: z.string(),
  endpoint: VendorWebhookEndpointSchema,
});
export type CreateVendorWebhookEndpointResponse = z.infer<
  typeof CreateVendorWebhookEndpointResponseSchema
>;

/** GET /api/admin/pos-vendors/{vendor}/webhook-endpoints */
export function listVendorWebhookEndpoints(
  vendorId: number,
  options: RequestOptions = {},
): Promise<VendorWebhookEndpoint[]> {
  return apiFetch(
    `/api/admin/pos-vendors/${vendorId}/webhook-endpoints`,
    VendorWebhookEndpointListResponseSchema,
    { signal: options.signal },
  ).then((r) => r.data);
}

/** POST /api/admin/pos-vendors/{vendor}/webhook-endpoints — 201 with the once-only secret. */
export function createVendorWebhookEndpoint(
  vendorId: number,
  body: { url: string; events: string[] },
  options: RequestOptions = {},
): Promise<CreateVendorWebhookEndpointResponse> {
  return apiFetch(
    `/api/admin/pos-vendors/${vendorId}/webhook-endpoints`,
    CreateVendorWebhookEndpointResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/** DELETE /api/admin/pos-vendors/{vendor}/webhook-endpoints/{id} */
export async function deleteVendorWebhookEndpoint(
  vendorId: number,
  endpointId: number,
  options: RequestOptions = {},
): Promise<void> {
  await apiFetch(
    `/api/admin/pos-vendors/${vendorId}/webhook-endpoints/${endpointId}`,
    z.object({ data: z.object({ deleted: z.boolean() }) }),
    { method: "DELETE", signal: options.signal },
  );
}

/** POST …/webhook-endpoints/{id}/test — queues one signed `webhook.test`. */
export async function testVendorWebhookEndpoint(
  vendorId: number,
  endpointId: number,
  options: RequestOptions = {},
): Promise<void> {
  await apiFetch(
    `/api/admin/pos-vendors/${vendorId}/webhook-endpoints/${endpointId}/test`,
    z.object({ data: z.object({ delivery: z.object({ id: z.number().int(), event: z.string(), status: z.string() }) }) }),
    { method: "POST", signal: options.signal },
  );
}
