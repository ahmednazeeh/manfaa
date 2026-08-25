import { z } from "zod";
import {
  ApiError,
  apiBaseUrl,
  apiFetch,
  apiFetchBlob,
  apiFetchDownload,
} from "./client";
import {
  BankSlugSchema,
  CashbackPercentInputSchema,
  ClaimStateSchema,
  dataWrapped,
  FeePercentInputSchema,
  FeeTreatmentSchema,
  MAX_CASHBACK_BP,
  MerchantChannelSchema,
  MerchantStatusSchema,
  paginated,
  PayoutBatchSchema,
  percentInput,
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
 * schedule, typed platform settings, the GST-on-the-platform-fee registration
 * and switch, superadmin-only admin account management, island zoning
 * (polygon CRUD with server-side branch assignment), and the superadmin
 * reporting surface (cashback / payouts / earnings, previewed as JSON and
 * exported as .xlsx). All amounts sent and received are integer laari; all
 * RATES travel as 2-decimal percent strings (PLAN §1 wire format) — basis
 * points are the API's internal representation and never appear in a body,
 * the GST rate included.
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

export const MatchSettlementPaymentRequestSchema = z.object({
  /**
   * WHAT THE STATEMENT SAYS ARRIVED, integer laari, at least 1 — OPTIONAL
   * (owner, 2026-08-25).
   *
   * The merchant's `amount_laari` is a CLAIM; the bank credit is the FACT,
   * and it is the fact that funds the batch. On this path there is no bank
   * row to read, so a reviewer holding the statement is the only source of
   * it: what they type is stamped as `received_laari` and is what allocates.
   *
   * OMITTING IT keeps whatever figure the row already carries — the
   * verifier's own stamp where the poller matched it, and the claim where
   * nobody ever had a better number, which is exactly what every hand match
   * spent before this field existed. So a screen may prefill it with the
   * claim (the best guess when nothing better is known) provided the
   * reviewer can see and change it before committing.
   *
   * A figure smaller than the batch's outstanding flows down the existing
   * partial path — whole lines oldest-first, the remainder still owed — and
   * a larger one parks the excess as merchant wallet credit. No new branch.
   */
  received_laari: z.number().int().min(1).max(100_000_000).optional(),
  /**
   * THE REFERENCE THE REVIEWER READ off the same statement line (verifier
   * round, 2026-08-25).
   *
   * A payment the merchant left blank was hand-matched with nothing written
   * to `bank_ref` or `matched_trx_refs` at all, so the credit stayed
   * "unspent" for every other path and a later wallet top-up or customer
   * order could take the same transfer a second time. Optional, because an
   * admin also reconciles payments nobody ever quoted a reference for — but
   * send it whenever the statement line is in front of you.
   */
  bank_ref: z.string().min(1).max(128).optional(),
});
export type MatchSettlementPaymentRequest = z.infer<
  typeof MatchSettlementPaymentRequestSchema
>;

/** POST /api/admin/payments/{id}/match — confirms a payment; returns the settlement. */
export function matchAdminSettlementPayment(
  paymentId: number,
  body: MatchSettlementPaymentRequest = {},
  options: RequestOptions = {},
): Promise<AdminSettlementResponse> {
  return apiFetch(
    `/api/admin/payments/${paymentId}/match`,
    AdminSettlementResponseSchema,
    { method: "POST", body, signal: options.signal },
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
  /**
   * WHAT THE STATEMENT SAYS ARRIVED, integer laari, at least 1 — REQUIRED
   * (owner, 2026-08-25). The server 422s without it.
   *
   * There is no bank row on this path, so nothing on the server can discover
   * what actually landed; the merchant's `amount_laari` is a CLAIM and
   * crediting it unseen is how a typo becomes money. The reviewer is looking
   * at the statement line — they type what it says, and the wallet is
   * credited that.
   *
   * Deliberately NOT defaulted server-side to the claim: a default would be
   * the old behaviour wearing a new field's name. A UI may prefill the field
   * with the claim (it is the best guess when nothing better is known) as
   * long as the reviewer can see and change it before committing.
   */
  received_laari: z.number().int().min(1),
});
export type MatchWalletTopUpRequest = z.infer<
  typeof MatchWalletTopUpRequestSchema
>;

/**
 * POST /api/admin/wallet-top-ups/{id}/match — confirms the transfer by hand
 * and credits the wallet in the same transaction; returns the row as
 * `matched` with its `wallet_transaction_id` and `received_laari` set to the
 * figure the reviewer stated. 422 when `received_laari` is missing, or when
 * the merchant gave no reference and the body carries none; 409 when the
 * claim is no longer pending (a poll matched it first, or it was already
 * reviewed) or the reference is already booked.
 *
 * The body is REQUIRED — `received_laari` has no default, so there is no
 * empty-body call any more.
 */
export function matchWalletTopUp(
  id: number,
  body: MatchWalletTopUpRequest,
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
// GST on the platform fee (owner, 2026-08-24)
// ---------------------------------------------------------------------------

/**
 * What a tax invoice must be able to name before the platform may charge
 * tax. These are REQUEST KEYS, echoed back in `missing_identity_fields` so a
 * form can highlight the exact inputs the switch is waiting on.
 */
export const TaxIdentityFieldSchema = z.enum([
  "gst_tin",
  "gst_business_name",
  "gst_activity_number",
]);
export type TaxIdentityField = z.infer<typeof TaxIdentityFieldSchema>;

/** The three in form order. */
export const TAX_IDENTITY_FIELDS = TaxIdentityFieldSchema.options;

/**
 * The GST rate's own bounds, in basis points — the mirror of the server's
 * `PercentRate::between(0, Percent::MAX_BP)`.
 *
 * ZERO IS LEGAL and is not the same thing as disabled: it is what a rate
 * looks like while the registration is pending. The ceiling is the same
 * structural 20.00% bound §4 puts on every other rate on the platform.
 */
export const GST_RATE_MIN_BP = 0;
export const GST_RATE_MAX_BP = MAX_CASHBACK_BP;

/** The rate as a REQUEST may send it: "8", "8.5", "8.00" or 8.5. */
export const GstRatePercentInputSchema = percentInput(
  GST_RATE_MIN_BP,
  GST_RATE_MAX_BP,
);
export type GstRatePercentInput = z.infer<typeof GstRatePercentInputSchema>;

/**
 * The platform's tax registration and the one switch that starts charging.
 * Its own endpoint rather than a typed platform setting because a setting is
 * an integer and a TIN, a business name and an activity number are strings.
 *
 * FOUR THINGS A PANEL BUILT ON THIS MUST GET RIGHT:
 *
 *  1. READ by any admin, WRITE by a SUPERADMIN only (403 otherwise) — the
 *     same gating the platform's bank accounts carry, for a stronger reason:
 *     this switch changes what every merchant owes on every sale from the
 *     moment it is thrown.
 *  2. ENABLING NEEDS AN IDENTITY. A GST-registered platform issues tax
 *     invoices, and an invoice that cannot name the registrant is not one.
 *     `missing_identity_fields` is what the switch is still waiting on and
 *     `can_enable` is the same answer as a boolean — say it on the screen
 *     BEFORE the 422 does. The identity and the switch may travel in ONE
 *     request; the server judges the row as it would be saved.
 *  3. NOTHING HERE TOUCHES AN EXISTING SALE. Every transaction carries the
 *     rate and treatment it was priced under (`fee_gst_percent`,
 *     `fee_treatment`), and every report, settlement and journal reads that
 *     stamp. Enabling, re-rating and switching treatment price NEW sales
 *     only — a confirm dialog that promises otherwise is lying.
 *  4. ENABLING ANNOUNCES ITSELF. The transition to enabled fires the
 *     `gst_now_applies` notification to every active merchant's settlement
 *     staff, ONCE. A rate edit, a treatment switch and re-saving an already
 *     enabled row send nothing — so a panel must not offer "re-notify".
 *
 * `enabled_at` is stamped on the TRANSITION, not on every save: it is the
 * instant the platform started charging tax, which is the first thing an
 * auditor asks for. A later rate edit leaves it exactly where it is.
 */
export const TaxSettingsSchema = z.object({
  gst_enabled: z.boolean(),
  /**
   * PLAN §1 wire format: a 2-decimal percent STRING ("8.00"), never basis
   * points. Keep it a string — parsing it to a number to render it is how a
   * rate loses a digit; use `percentToBp` only to compare or to drive a
   * slider.
   */
  gst_rate_percent: PercentSchema,
  /** The three a tax invoice must name. Null until the platform fills them. */
  gst_tin: z.string().nullable(),
  gst_business_name: z.string().nullable(),
  gst_activity_number: z.string().nullable(),
  fee_treatment: FeeTreatmentSchema,
  /** English prose from the API; localise off `fee_treatment`, not off this. */
  fee_treatment_label: z.string(),
  /** When charging STARTED, stamped on the transition only. Null while off. */
  enabled_at: z.string().nullable(),
  /**
   * Which identity fields are still blank, by their request key — the exact
   * list the 422 would name. Empty when the switch is free to move.
   */
  missing_identity_fields: z.array(TaxIdentityFieldSchema),
  /** `missing_identity_fields.length === 0`, said as the question a form asks. */
  can_enable: z.boolean(),
});
export type TaxSettings = z.infer<typeof TaxSettingsSchema>;

export const TaxSettingsResponseSchema = dataWrapped(TaxSettingsSchema);
export type TaxSettingsResponse = z.infer<typeof TaxSettingsResponseSchema>;

/**
 * GET /api/admin/platform/tax-settings — readable by ANY admin (401 without
 * an admin session). Answers the single settings row, seeded disabled.
 */
export function getAdminTaxSettings(
  options: RequestOptions = {},
): Promise<TaxSettingsResponse> {
  return apiFetch(
    "/api/admin/platform/tax-settings",
    TaxSettingsResponseSchema,
    { signal: options.signal },
  );
}

/**
 * The PATCH body. Every field is optional and only what is SENT is written,
 * so a form may save the identity alone, flip the switch alone, or do both
 * at once — the refusal is judged against the resulting row, which is what
 * makes "fill in the TIN and enable" a single legal request.
 */
export const UpdateTaxSettingsRequestSchema = z.object({
  gst_enabled: z.boolean().optional(),
  /**
   * The rate as a request may send it — "8", "8.5", "8.00" or the number 8.5
   * — bounded 0.00%–20.00% exactly as the server bounds it. ZERO IS LEGAL: it
   * is what a rate looks like while the registration is pending.
   */
  gst_rate_percent: GstRatePercentInputSchema.optional(),
  gst_tin: z.string().nullable().optional(),
  gst_business_name: z.string().nullable().optional(),
  gst_activity_number: z.string().nullable().optional(),
  fee_treatment: FeeTreatmentSchema.optional(),
});
export type UpdateTaxSettingsRequest = z.infer<
  typeof UpdateTaxSettingsRequestSchema
>;

/**
 * PATCH /api/admin/platform/tax-settings — SUPERADMIN only (403 otherwise).
 * Answers the refreshed row. 422 when the request would leave GST enabled
 * without the full identity, naming the missing fields in its message; the
 * same fields are already in `missing_identity_fields` on the read, so a
 * panel should never need the 422 to know.
 */
export function updateAdminTaxSettings(
  body: UpdateTaxSettingsRequest,
  options: RequestOptions = {},
): Promise<TaxSettingsResponse> {
  return apiFetch(
    "/api/admin/platform/tax-settings",
    TaxSettingsResponseSchema,
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

// ---------------------------------------------------------------------------
// Superadmin reports (owner, 2026-08-24)
// ---------------------------------------------------------------------------

/**
 * The three reports, by their `{report}` path segment. Anything else is a
 * router 404, so the panel should build its tabs from REPORT_KINDS rather
 * than from a hand-typed list that can drift from the route constraint.
 */
export const ReportKindSchema = z.enum(["cashback", "payouts", "earnings"]);
export type ReportKind = z.infer<typeof ReportKindSchema>;

/** The kinds in workbook order, for tabs and for a route param guard. */
export const REPORT_KINDS = ReportKindSchema.options;

/**
 * Whether `include_reversed` can change a given report at all — false for
 * payouts (every row was paid, and paid is terminal) and for earnings (built
 * from the ledger, which always keeps reversal journals).
 *
 * A MIRROR of the server's Report::reversedRowsApply(), and used only where
 * there is no server answer to hand: the export filename fallback for a proxy
 * that ate Content-Disposition. Anywhere a preview response is available,
 * read `reversed_rows_apply` off it instead — the server is the authority,
 * and a browser-side list of report names is exactly the drift the header
 * block is served verbatim to avoid.
 */
export function reportUsesReversedRows(kind: ReportKind): boolean {
  return kind === "cashback";
}

/**
 * What a preview cell MEANS, which is the only thing that says how to render
 * it — the wire carries bare scalars and nothing else distinguishes 2000
 * laari from 2000 basis points:
 *
 *   text    string ("" for an absent value, never null)
 *   int     number | null
 *   money   number | null — integer LAARI (formatLaari)
 *   percent number | null — integer BASIS POINTS (formatBpPercent)
 *   date    string | null — ISO-8601 carrying the +05:00 business offset
 *
 * `percent` is the one exception to the package's percent-string wire rule
 * (PLAN §1): a report cell is a rendered figure rather than a rate a caller
 * can submit, and the workbook needs the same integer the panel shows.
 */
export const ReportColumnTypeSchema = z.enum([
  "text",
  "int",
  "money",
  "percent",
  "date",
]);
export type ReportColumnType = z.infer<typeof ReportColumnTypeSchema>;

/** One column: the machine key, the human label, and how to render the cell. */
export const ReportColumnSchema = z.object({
  key: z.string(),
  label: z.string(),
  type: ReportColumnTypeSchema,
});
export type ReportColumn = z.infer<typeof ReportColumnSchema>;

/** A single cell, typed by its column's `type`. */
export const ReportCellSchema = z.union([z.string(), z.number(), z.null()]);
export type ReportCell = z.infer<typeof ReportCellSchema>;

/**
 * A row is POSITIONAL — `row[i]` belongs to `columns[i]` — exactly as the API
 * sends it. The column order IS the sheet: a row keyed by name can disagree
 * with its header, and a finance table where column six is sometimes GST and
 * sometimes the fee is worse than no table at all. Index into `columns`;
 * never look a cell up by key.
 */
export const ReportRowSchema = z.array(ReportCellSchema);
export type ReportRow = z.infer<typeof ReportRowSchema>;

/** The window the API echoes back, in BUSINESS-timezone dates. */
export const ReportPeriodSchema = z.object({
  from: z.string(),
  to: z.string(),
  /** IANA name, e.g. "Indian/Maldives" — the tz every date in the report is stated in. */
  timezone: z.string(),
  /** Whole days covered, both ends inclusive. */
  days: z.number().int(),
});
export type ReportPeriod = z.infer<typeof ReportPeriodSchema>;

/**
 * The per-report totals block. Deliberately an open record: the three reports
 * answer three different questions (cashback totals by state, the payout tie,
 * the ledger-derived money trace), and a union of three shapes would have to
 * be revised here every time the ledger grows an account. Read it with
 * reportSummaryNumber / reportSummaryList, whose null return is the honest
 * answer for a key this report does not carry.
 *
 * The keys each report emits (all `_laari` values are integer laari):
 *
 *   cashback  transactions{count,eligible_laari,cashback_laari,fee_laari,
 *             fee_forgone_laari,gst_laari,gross_due_laari,discount_laari,
 *             forgiveness_laari,collected_laari}, by_state[] (state,count,…),
 *             settlements{…}. `fee_forgone_laari` is the fee given away by
 *             platform fee promotions — a memo figure alongside `fee_laari`,
 *             deliberately NOT part of `gross_due_laari`, because nobody owes
 *             it and no journal carries it.
 *   payouts   transactions{count,cashback_laari}, payout_items{count,
 *             amount_laari,paid_laari}, batches{…}, wallet_withdrawals{…},
 *             ties{transactions_cashback_laari,payout_items_paid_laari,
 *             batches_paid_laari} — three sheets that must state one number
 *   earnings  fee_revenue_laari, prompt_discounts_laari,
 *             shortfall_forgiveness_laari, net_fee_income_laari,
 *             gst_collected_laari, platform_funded_rewards_laari,
 *             bad_debt_laari, net_platform_earnings_laari,
 *             accrued_vs_collected{fees_accrued_laari,
 *             fees_collected_bank_laari,fees_collected_wallet_laari,
 *             fees_collected_laari}, postings{count,debit_laari,credit_laari}
 */
export const ReportSummarySchema = z.record(z.string(), z.unknown());
export type ReportSummary = z.infer<typeof ReportSummarySchema>;

/** One line of the workbook's table of contents: a sheet and how deep it runs. */
export const ReportSheetIndexSchema = z.object({
  title: z.string(),
  row_count: z.number().int(),
});
export type ReportSheetIndex = z.infer<typeof ReportSheetIndexSchema>;

/** One `label: value` line of the header block — "Period", "Merchant", … */
export const ReportHeaderFactSchema = z.object({
  label: z.string(),
  value: z.string(),
});
export type ReportHeaderFact = z.infer<typeof ReportHeaderFactSchema>;

/**
 * The prose block the workbook's Summary sheet opens with (owner,
 * 2026-08-24), carried verbatim so the panel can show the reader the same
 * sentences the file will — before they download it.
 *
 * `facts` is the provenance: report name, period, timezone, merchant filter
 * and whether reversed rows are in this render. `notes` is the glossary that
 * exists because Manfaa's two money flows share one blurry word:
 *
 *   MERCHANT SETTLEMENT   money IN  — the merchant pays Manfaa
 *   CUSTOMER PAYOUT       money OUT — Manfaa pays the customer
 *
 * "Settlement" alone says which side is settling to nobody, and a tax
 * professional reading this months later was never in the room. The earnings
 * report adds a third note there: its reversal journals are ALWAYS included,
 * whatever `include_reversed` says, because on the ledger the reversal is the
 * posting that takes the fee back out of income.
 *
 * It is NOT data. The block never enters the preview's `rows`, so it cannot
 * shift a positional cell's meaning or be caught by a totals formula. Render
 * it as prose; `notes` is a list of whole sentences, already written.
 */
export const ReportHeaderBlockSchema = z.object({
  title: z.string(),
  facts: z.array(ReportHeaderFactSchema),
  notes: z.array(z.string()),
});
export type ReportHeaderBlock = z.infer<typeof ReportHeaderBlockSchema>;

/**
 * The primary sheet's head: its title, its columns, and up to 50 rows.
 *
 * ALWAYS FULLY POPULATED. The .xlsx may print a repeated label once per
 * block — the payouts workbook's Payouts sheet blanks `batch_ref` on every
 * row after the first of a batch, so a human reading forty rows sees the
 * reference once instead of forty times — but that blanking happens in the
 * WORKBOOK WRITER, at the moment a cell is written, and never in the data.
 * A preview cell is never holed, so no consumer has to back-fill one, and
 * every column still totals and filters. Render the rows as they arrive.
 *
 * That sheet also carries a plain `batch_id` column ("Batch key", type
 * `int`) beside the reference: it is on EVERY row, so an autofilter catches
 * all of a batch, and it is what tells two batches apart when a cancelled
 * one has left its reference in use twice.
 *
 * Neither is visible here today — the payouts PRIMARY sheet is Transactions
 * — but the rule holds for every sheet the preview may name.
 */
export const ReportPreviewSheetSchema = z.object({
  sheet: z.string(),
  columns: z.array(ReportColumnSchema),
  rows: z.array(ReportRowSchema),
});
export type ReportPreviewSheet = z.infer<typeof ReportPreviewSheetSchema>;

/**
 * GET /api/admin/reports/{report} — the on-screen report: totals, the head of
 * the primary sheet, and an index of every sheet the .xlsx would hold.
 *
 * `row_count` and `capped` describe the PRIMARY sheet only (cashback →
 * Transactions, payouts → Transactions, earnings → Postings): `capped` true
 * means the preview stops at REPORT_PREVIEW_ROWS and the export is where the
 * rest lives. A preview writes no audit row; an export does.
 *
 * `include_reversed` is the server's echo of what it actually built, not of
 * what was asked — read the preview's setting off THIS rather than off the
 * caller's own state, so a toggle mid-flight cannot label a table with a
 * setting the rows on it were not built under.
 *
 * `reversed_rows_apply` says whether that setting can change this report at
 * all: false on payouts (every row was paid, and paid is terminal) and on
 * earnings (ledger-derived, so reversal journals always stay). Label the
 * setting off BOTH — "reversed rows included" over a report the flag did
 * nothing to is the same misinformation the workbook's header block was
 * rewritten to stop telling.
 *
 * `header` is the workbook's own header block (null for a report that has
 * none). Every preview cell is MASKED — `Ahm*** Naz***`, `****3098`; the
 * .xlsx from /export is the one unmasked render.
 */
export const ReportPreviewSchema = z.object({
  report: ReportKindSchema,
  period: ReportPeriodSchema,
  merchant_id: z.number().int().nullable(),
  include_reversed: z.boolean(),
  reversed_rows_apply: z.boolean(),
  header: ReportHeaderBlockSchema.nullable(),
  summary: ReportSummarySchema,
  preview: ReportPreviewSheetSchema,
  sheets: z.array(ReportSheetIndexSchema),
  row_count: z.number().int(),
  capped: z.boolean(),
});
export type ReportPreview = z.infer<typeof ReportPreviewSchema>;

/** Rows the JSON preview carries before `capped` goes true. */
export const REPORT_PREVIEW_ROWS = 50;

/** The server's interactive ceiling: over this the export is a 422, not a file. */
export const REPORT_ROW_LIMIT = 50_000;

/** The longest span a period may cover — a year and a day (ReportPeriod::MAX_DAYS). */
export const REPORT_MAX_DAYS = 366;

/**
 * The window and the optional filter, identical on both endpoints.
 *
 * `from`/`to` are Y-m-d dates in the BUSINESS timezone, both ends inclusive —
 * 2026-08-01 means the Maldivian first of August, so a transaction at 02:00
 * Malé on the 1st belongs to August even though it was 31 July in UTC. Do not
 * send an ISO instant; the API validates `date_format:Y-m-d`.
 */
export interface ReportPeriodParams {
  from: string;
  to: string;
  /** One merchant, or null/undefined for every merchant. */
  merchant_id?: number | null;
  /**
   * Whether rows for REVERSED transactions are in the report. Defaults to
   * false on both endpoints — a reversed sale is one that was undone, and a
   * finance report is safe to be wrong in the direction of leaving those out.
   *
   * Pass the SAME value to getReportPreview and downloadReportExport. The
   * whole point of the flag being on both is that the table on screen
   * describes the file the button produces; two different values make the
   * preview a lie about the export.
   *
   * It does NOT govern the earnings report, which is derived from the ledger
   * and always carries reversal JOURNALS: there the reversal is the posting
   * that takes the fee back OUT of income, so dropping it would overstate
   * what Manfaa earned. The flag removes reversed transaction ROWS from the
   * cashback report; the two reports are not in conflict, and each workbook
   * says so in its own header block.
   */
  include_reversed?: boolean;
}

function reportQuery(params: ReportPeriodParams): string {
  return queryString({
    from: params.from,
    to: params.to,
    merchant_id: params.merchant_id ?? undefined,
    // Sent only when true, and as "1" rather than "true". The API accepts
    // either spelling (its validation and its parse are one function), but
    // omitting the default keeps an ordinary report's URL — and so its
    // cache key and its server logs — byte-identical to what it has always
    // been, and "1" is the spelling a hand-typed URL uses.
    include_reversed: params.include_reversed === true ? "1" : undefined,
  });
}

/**
 * The 422 a period too big to build in one pass comes back as — distinct from
 * a validation 422, which carries `errors`. Catch it to say "narrow the
 * period" with the real numbers rather than a generic failure.
 */
export const ReportTooLargeSchema = z.object({
  code: z.literal("report_too_large"),
  message: z.string(),
  row_count: z.number().int(),
  limit: z.number().int(),
});
export type ReportTooLarge = z.infer<typeof ReportTooLargeSchema>;

/** The machine-readable code on that refusal (see apiErrorCode). */
export const REPORT_TOO_LARGE_CODE = "report_too_large";

/**
 * Reads the row-cap refusal off a thrown error, or null when the error is
 * anything else (a validation 422, a 403, a network failure). The refusal is
 * raised BEFORE the report is built, so it costs nothing to retry narrower.
 */
export function reportTooLargeDetail(error: unknown): ReportTooLarge | null {
  if (!(error instanceof ApiError) || error.status !== 422) {
    return null;
  }
  const parsed = ReportTooLargeSchema.safeParse(error.body);
  return parsed.success ? parsed.data : null;
}

/**
 * GET /api/admin/reports/{kind} — superadmin only (401 without an admin
 * session, 403 for an admin who is not a superadmin, 404 for an unknown
 * kind).
 */
export function getReportPreview(
  kind: ReportKind,
  params: ReportPeriodParams,
  options: RequestOptions = {},
): Promise<ReportPreview> {
  return apiFetch(
    `/api/admin/reports/${kind}${reportQuery(params)}`,
    ReportPreviewSchema,
    { signal: options.signal },
  );
}

/** The bytes of an export and the name it should be saved under. */
export interface ReportExportDownload {
  blob: Blob;
  /**
   * From Content-Disposition — `manfaa-{kind}-{from}-{to}.xlsx`, with
   * `-m{merchantId}` appended when the export was filtered to one merchant
   * and `-with-reversed` when reversed rows were included. Without those
   * suffixes two different exports of the same period want the same name,
   * and the second lands as `... (1).xlsx`.
   *
   * The reversed suffix is the more important of the two: the same shop and
   * the same month exported both ways produce files with DIFFERENT TOTALS,
   * and a reader who cannot tell them apart will eventually send the wrong
   * one to an accountant. It appears on the cashback export only — on
   * payouts and earnings the setting is inert, so both spellings produce the
   * same workbook and naming them differently would advertise a difference
   * in totals that does not exist.
   */
  filename: string;
}

/**
 * GET /api/admin/reports/{kind}/export — the same figures as an .xlsx.
 *
 * A credentialed fetch rather than a link or a window.open: the route needs
 * the admin session AND rejects a non-superadmin, and only a fetch can turn
 * that refusal (or the row cap) into a message on screen instead of a browser
 * tab showing JSON. The caller wraps `blob` in URL.createObjectURL, clicks an
 * anchor with `download = filename`, and revokes the object URL afterwards.
 *
 * Every call writes one `report_exports` audit row — this is the endpoint
 * that puts customer codes and the money trace into a file that leaves the
 * building — so fire it on a click, never on render or in an effect.
 *
 * It is also the UNMASKED render (owner, 2026-08-24): the workbook carries
 * full customer names, whole bank account numbers and full account names,
 * because its job is to be reconciled line by line against a bank statement
 * and then filed for tax, and `****4821` reconciles against nothing. The
 * preview stays masked. Same figures, same rows — a different artefact.
 */
export async function downloadReportExport(
  kind: ReportKind,
  params: ReportPeriodParams,
  options: RequestOptions = {},
): Promise<ReportExportDownload> {
  const { blob, filename } = await apiFetchDownload(
    `/api/admin/reports/${kind}/export${reportQuery(params)}`,
    { signal: options.signal },
  );

  return {
    blob,
    // The server names the file; the fallback only covers a proxy that eats
    // the header, and mirrors the same rule — merchant filter and the
    // reversed-rows choice both included, in that order.
    filename:
      filename ??
      `manfaa-${kind}-${params.from}-${params.to}${
        params.merchant_id == null ? "" : `-m${params.merchant_id}`
      }${
        params.include_reversed === true && reportUsesReversedRows(kind)
          ? "-with-reversed"
          : ""
      }.xlsx`,
  };
}

/**
 * One number out of a report summary, by path — `reportSummaryNumber(summary,
 * "transactions", "cashback_laari")`. Returns null when the key is absent or
 * is not a number, so a report that does not carry it renders as a dash
 * rather than as NaN. Money keys end `_laari` and are integer laari.
 */
export function reportSummaryNumber(
  summary: ReportSummary,
  ...path: string[]
): number | null {
  let cursor: unknown = summary;

  for (const key of path) {
    if (typeof cursor !== "object" || cursor === null) {
      return null;
    }
    cursor = (cursor as Record<string, unknown>)[key];
  }

  return typeof cursor === "number" && Number.isFinite(cursor) ? cursor : null;
}

/**
 * A list of objects out of a report summary — the cashback report's
 * `by_state` breakdown. Returns [] when the key is absent, so a caller can
 * map over it unconditionally; read each entry's figures with
 * reportSummaryNumber.
 */
export function reportSummaryList(
  summary: ReportSummary,
  key: string,
): ReportSummary[] {
  const value = summary[key];

  if (!Array.isArray(value)) {
    return [];
  }

  return value.filter(
    (entry): entry is ReportSummary =>
      typeof entry === "object" && entry !== null && !Array.isArray(entry),
  );
}

// ---------------------------------------------------------------------------
// The admin landing dashboard (owner, 2026-08-25)
// ---------------------------------------------------------------------------

/**
 * GET /api/admin/dashboard — the console's landing page in ONE request.
 *
 * One endpoint, one payload, one instant. A dashboard assembled from eight
 * parallel fetches answers from eight different moments and its tiles
 * disagree with each other about a settlement that matched while they were
 * in flight; this one cannot.
 *
 * FOUR PANELS, TWO AUDIENCES:
 *
 *   attention, auto_match, growth   EVERY admin. What is waiting on a human,
 *                                   whether the bank matcher is still alive,
 *                                   who signed up.
 *   money, series                   SUPERADMIN ONLY — the same gate the
 *                                   Reports page wears, for the same reason:
 *                                   these cross every merchant and every
 *                                   customer at once.
 *
 * The gated keys are ABSENT for a plain admin, never zeroed, and this
 * package models them optional for exactly that reason: "MVR 0.00" of
 * platform revenue is an ANSWER, and it is the wrong one. Never `?? 0` a
 * money field to make a type check pass. Read `can_view_money` — or narrow
 * with dashboardShowsMoney — and either render the panel or leave it off the
 * page.
 */

// ------------------------------------------------------------------ attention

/**
 * WHAT IS WAITING ON A HUMAN. Each count is its own queue's OWN predicate,
 * taken from the endpoint that serves that queue, so a badge can never say 3
 * while the list behind it shows 4:
 *
 *   settlements_payment_review   the settlement matching queue
 *   wallet_top_ups_pending       the wallet top-up matching queue
 *   store_reviews_pending        merchants awaiting store review
 *   change_requests_pending      merchant change requests
 *   holds_open                   transactions on hold (the hold-review queue)
 *   marketplace_kyb_pending      marketplace profiles awaiting KYB
 *
 * `total` is the sum of whatever keys the response carries.
 *
 * COUNTS OF WHAT THE LIST LISTS. `settlements_payment_review` counts BATCHES,
 * not receipts, because /settlements?state=payment_review lists batches — one
 * batch can carry several simultaneously-pending receipts, and counting those
 * made the tile say 2 over a screen showing one row. Point a tile at the
 * filtered list, never at the list's default tab.
 *
 * MARKETPLACE IS CONDITIONAL: with the platform flag off the key is ABSENT
 * rather than zero — off means every surface hides it, and a permanent
 * "0 KYB applications" tile is exactly the surface that rule is about. Read
 * it as `attention.marketplace_kyb_pending` and skip the tile on undefined;
 * do not default it to 0, which would put the tile back.
 */
export const DashboardAttentionSchema = z.object({
  settlements_payment_review: z.number().int(),
  wallet_top_ups_pending: z.number().int(),
  store_reviews_pending: z.number().int(),
  change_requests_pending: z.number().int(),
  holds_open: z.number().int(),
  /** Absent — not zero — while the marketplace flag is off. */
  marketplace_kyb_pending: z.number().int().optional(),
  /** The sum of the queues PRESENT in this response. */
  total: z.number().int(),
});
export type DashboardAttention = z.infer<typeof DashboardAttentionSchema>;

/**
 * The queues in the order the server counts them, for a tile row built by
 * mapping rather than by six hand-written lookups. Indexing `attention` with
 * one of these yields `number | undefined`; undefined means the queue is not
 * in this deployment (marketplace off), not that it is empty.
 */
export const DASHBOARD_ATTENTION_QUEUES = [
  "settlements_payment_review",
  "wallet_top_ups_pending",
  "store_reviews_pending",
  "change_requests_pending",
  "holds_open",
  "marketplace_kyb_pending",
] as const;
export type DashboardAttentionQueue =
  (typeof DASHBOARD_ATTENTION_QUEUES)[number];

// ----------------------------------------------------------------- auto match

/**
 * WHY a pending transfer is not being watched — four machine reasons,
 * because they call for four different actions:
 *
 *   window_expired     the poll window ran out; match it by hand
 *   never_watched      it arrived while the switch was down
 *   no_verify_profile  a platform bank account is not routed to a read
 *                      profile — a CONFIGURATION fault, and the loudest of
 *                      the four
 *   auto_verify_off    the platform switch is down, so every transfer is now
 *                      manual work
 *
 * A mirror of the server's BankWatch reasons, in the order it lists them.
 */
export const DASHBOARD_WAITING_REASONS = [
  "window_expired",
  "never_watched",
  "no_verify_profile",
  "auto_verify_off",
] as const;
export type DashboardWaitingReason = (typeof DASHBOARD_WAITING_REASONS)[number];

/** Pending and unwatched, split by why. `total` is the four added up. */
export const DashboardWaitingOnHumanSchema = z.object({
  total: z.number().int(),
  window_expired: z.number().int(),
  never_watched: z.number().int(),
  no_verify_profile: z.number().int(),
  auto_verify_off: z.number().int(),
});
export type DashboardWaitingOnHuman = z.infer<
  typeof DashboardWaitingOnHumanSchema
>;

/**
 * The period's matches split by who found them, so a FALLING auto rate is
 * visible before the queue grows.
 *
 * `auto_rate_percent` is a 2-decimal percent STRING (PLAN §1 wire format) —
 * "66.67" — and is NULL when nothing matched at all. Null is not 0%: a rate
 * over no matches is nothing to report, and printing "0.00%" for a quiet
 * hour reads as a stall. Render null as a dash.
 */
export const DashboardMatchedSplitSchema = z.object({
  total: z.number().int(),
  auto: z.number().int(),
  manual: z.number().int(),
  /**
   * Matches in the period where the bank credited something OTHER than the
   * merchant typed (owner, 2026-08-25) — a subset of `total`, cutting across
   * the auto/manual split rather than partitioning it.
   *
   * NOT an error count. The credited figure is the bank's and the money is
   * real; a merchant who typed MVR 20.00 and sent MVR 10.00 is correctly
   * credited the 10.00. It is worth a person's eye and nothing louder, so
   * render it as a quiet fact — a rising one says the slips are worth a look.
   */
  differing_amounts: z.number().int(),
  /** Percent string, or null when `total` is 0 — never coerce to "0.00". */
  auto_rate_percent: PercentSchema.nullable(),
});
export type DashboardMatchedSplit = z.infer<typeof DashboardMatchedSplitSchema>;

/**
 * One transfer flow's HEALTH, which is the question — not its queue length.
 *
 * A pending transfer is either one the server is actively polling the bank
 * for (fine, leave it alone) or one NOBODY is looking at any more, which is
 * a person's job that nothing on the platform will do. A single "8 pending"
 * tile hides that difference, and the difference is the entire panel.
 *
 * `expired_unmatched_24h` counts the windows that lapsed in the last day —
 * the shape of a problem that started recently, as opposed to a backlog that
 * has always been there.
 */
export const DashboardTransferHealthSchema = z.object({
  pending_total: z.number().int(),
  /** Pending rows with an open poll window right now. */
  watching_now: z.number().int(),
  waiting_on_human: DashboardWaitingOnHumanSchema,
  /** Watch windows that ran out in the last 24 hours. */
  expired_unmatched_24h: z.number().int(),
  matched_in_period: DashboardMatchedSplitSchema,
});
export type DashboardTransferHealth = z.infer<
  typeof DashboardTransferHealthSchema
>;

/**
 * BOTH FLOWS, SEPARATELY. Settlement payments and wallet top-ups are matched
 * by two different verifiers against two different tables, and one stalling
 * while the other is healthy is precisely the fact this panel exists to show
 * — so never sum them into a single "pending transfers" number.
 */
export const DashboardAutoMatchSchema = z.object({
  settlement_payments: DashboardTransferHealthSchema,
  wallet_top_ups: DashboardTransferHealthSchema,
});
export type DashboardAutoMatch = z.infer<typeof DashboardAutoMatchSchema>;

/** The two flows, for a panel that renders the same card twice. */
export const DASHBOARD_TRANSFER_FLOWS = [
  "settlement_payments",
  "wallet_top_ups",
] as const;
export type DashboardTransferFlow = (typeof DASHBOARD_TRANSFER_FLOWS)[number];

// --------------------------------------------------------------------- growth

/**
 * WHO JOINED — counts of people and shops, never money, which is why every
 * admin sees them.
 *
 * THREE numbers about stores, not two, because "new merchants" is genuinely
 * ambiguous: `active_total` is the estate trading today,
 * `new_active_in_period` are the ones that registered in the window AND are
 * trading now, and `registered_in_period` counts every store that signed up
 * whatever became of it. The difference between the last two IS the approval
 * queue — a signup wave still sitting in review is a fact worth showing.
 */
export const DashboardGrowthSchema = z.object({
  customers: z.object({
    total: z.number().int(),
    new_in_period: z.number().int(),
  }),
  merchants: z.object({
    active_total: z.number().int(),
    new_active_in_period: z.number().int(),
    registered_in_period: z.number().int(),
  }),
});
export type DashboardGrowth = z.infer<typeof DashboardGrowthSchema>;

// ---------------------------------------------------------------------- money

/**
 * THE SIX MONEY FIGURES, all integer LAARI (formatLaari) — SUPERADMIN ONLY.
 *
 * Not one of them is defined by the dashboard: each is read from the report
 * class that owns its definition, so this panel can never disagree with the
 * Reports page.
 *
 *   cashback_generated_laari        cashback on sales, dated by the SALE
 *                                   (occurred_at, business time), reversed
 *                                   sales excluded — the cashback report
 *   platform_fees_net_laari         fee revenue less prompt discounts less
 *                                   forgiven shortfalls, from the LEDGER by
 *                                   posted_at — the earnings report
 *   gst_collected_laari             the same ledger pass, kept SEPARATE
 *                                   because GST is a liability owed to MIRA,
 *                                   not income. Never add it to the fees.
 *   fee_forgone_to_promotions_laari the platform fee those sales would have
 *                                   paid at the merchant's §4 tier, less what
 *                                   a fee promotion actually charged them —
 *                                   the cashback report again
 *   collected_from_merchants_laari  what merchants actually paid on the
 *                                   batches the period raised
 *   paid_out_to_customers_laari     cashback whose PAID event landed in the
 *                                   period — the payout report
 *
 * TWO CLOCKS, DELIBERATELY: cashback is dated by the sale and fees by the
 * journal, because that is what the two reports do. `cashback_generated` and
 * `platform_fees_net` are therefore NOT two views of one month's trade and
 * will not tie — the field names carry their own basis, and a tile that
 * subtracts one from the other is stating a number nobody owns.
 *
 * `fee_forgone_to_promotions_laari` sits on the SALE clock with cashback, not
 * on the journal clock with the fees, because it is not a ledger movement at
 * all: a fee we never charged posts nothing. It is the acquisition spend —
 * what the fee promotions cost the platform — and it is NOT part of
 * `platform_fees_net_laari`; adding the two together states a revenue figure
 * that never existed.
 */
export const DashboardMoneyTotalsSchema = z.object({
  cashback_generated_laari: z.number().int(),
  platform_fees_net_laari: z.number().int(),
  /** A liability owed to MIRA, not income — show it apart from the fees. */
  gst_collected_laari: z.number().int(),
  /**
   * Fee given away by platform fee promotions, on the SALE clock. A memo
   * figure with no journal behind it — never added to, or subtracted from,
   * `platform_fees_net_laari`.
   */
  fee_forgone_to_promotions_laari: z.number().int(),
  collected_from_merchants_laari: z.number().int(),
  paid_out_to_customers_laari: z.number().int(),
});
export type DashboardMoneyTotals = z.infer<typeof DashboardMoneyTotalsSchema>;

/**
 * The window immediately before this one, of EQUAL LENGTH and adjacent to it
 * — 20 days of August answered by the 20 days that ran up to 31 July, not by
 * the whole of July. It is the only comparison that makes a month-to-date
 * figure mean anything, and `period` says exactly which days it covers so an
 * arrow can be labelled with the truth rather than with "last month".
 */
export const DashboardPreviousMoneySchema = DashboardMoneyTotalsSchema.extend({
  period: ReportPeriodSchema,
});
export type DashboardPreviousMoney = z.infer<
  typeof DashboardPreviousMoneySchema
>;

/** The money panel: this period's five figures, and the preceding window's. */
export const DashboardMoneySchema = DashboardMoneyTotalsSchema.extend({
  previous: DashboardPreviousMoneySchema,
});
export type DashboardMoney = z.infer<typeof DashboardMoneySchema>;

// --------------------------------------------------------------------- series

/**
 * ONE ROW PER DAY of the period, EVERY day of it — SUPERADMIN ONLY, gated
 * with the money because a daily chart of cashback, collections and payouts
 * is the money panel a summation away.
 *
 * ZERO-FILLED by the server, and that is not cosmetic: a chart drawn from
 * sparse rows draws a straight line across a quiet week and makes it look
 * like trade. Every date from `from` to `to` inclusive appears exactly once,
 * in order. Plot the rows as they arrive — do not filter the zeros out.
 *
 * `date` is a Y-m-d BUSINESS day (Indian/Maldives): a bar labelled 4 August
 * holds the Maldivian 4th, the same day the Reports page puts those sales
 * in. Parse it as a plain date, never with `new Date(date)` into a local
 * midnight that can slide the bar a day in a westward browser.
 *
 * All four figures are integer laari:
 *
 *   cashback_laari     accrued by the SALE's date
 *   fee_accrued_laari  the platform fee on those same sales, ACCRUED with
 *                      them — deliberately NOT the money panel's
 *                      `platform_fees_net_laari`, which is what the ledger
 *                      recognised after discounts. Two honest numbers about
 *                      fees; the names say which is which, and they are not
 *                      meant to tie.
 *   collected_laari    paid by merchants on the batches raised that day
 *   paid_out_laari     cashback whose PAID event landed that day
 *
 * The other three DO tie: summed over the period they equal the money
 * panel's cashback_generated, collected_from_merchants and
 * paid_out_to_customers.
 */
export const DashboardSeriesEntrySchema = z.object({
  /** Y-m-d in BUSINESS time, one per day of the period, in order. */
  date: z.string(),
  cashback_laari: z.number().int(),
  /** The accrual, not the ledger net — see the note above. */
  fee_accrued_laari: z.number().int(),
  collected_laari: z.number().int(),
  paid_out_laari: z.number().int(),
});
export type DashboardSeriesEntry = z.infer<typeof DashboardSeriesEntrySchema>;

// ------------------------------------------------------------------ the whole

/**
 * The whole landing payload.
 *
 * `money` and `series` are OPTIONAL because a plain admin's response OMITS
 * them — the gate removes the panels, it does not blank them. Modelling them
 * as nullable-with-a-zero-default would let the console print "MVR 0.00
 * platform revenue" to an admin who is simply not allowed to know, which is
 * a fabricated figure on a finance screen. `can_view_money` says which of
 * the two payloads you are holding, so the page can lay itself out without
 * probing for keys; dashboardShowsMoney narrows both at once.
 *
 * `period` is the window the server actually used, echoed back in BUSINESS
 * dates — read the heading off THIS, not off what was asked, so a default
 * window (the business month in progress) can label itself.
 *
 * `generated_at` is the SERVER's clock as an ISO-8601 instant, so an "as of"
 * line does not print a handset's idea of now.
 */
export const AdminDashboardSchema = z.object({
  period: ReportPeriodSchema,
  /** ISO-8601 instant from the server's clock (UTC). */
  generated_at: z.string(),
  /** True for a superadmin — and only then do `money` and `series` exist. */
  can_view_money: z.boolean(),
  attention: DashboardAttentionSchema,
  auto_match: DashboardAutoMatchSchema,
  growth: DashboardGrowthSchema,
  /** Superadmin only; ABSENT (not zeroed) for a plain admin. */
  money: DashboardMoneySchema.optional(),
  /** Superadmin only; ABSENT (not []) for a plain admin. */
  series: z.array(DashboardSeriesEntrySchema).optional(),
});
export type AdminDashboard = z.infer<typeof AdminDashboardSchema>;

/** A dashboard that carries the gated panels — see dashboardShowsMoney. */
export type AdminDashboardWithMoney = AdminDashboard & {
  money: DashboardMoney;
  series: DashboardSeriesEntry[];
};

/**
 * Narrows a dashboard to the superadmin payload, so `money` and `series` can
 * be read without optional chaining and without a default that would invent
 * a figure. Tests the flag AND the keys: an empty money panel is not a money
 * panel, whatever the flag says.
 */
export function dashboardShowsMoney(
  dashboard: AdminDashboard,
): dashboard is AdminDashboardWithMoney {
  return (
    dashboard.can_view_money &&
    dashboard.money !== undefined &&
    dashboard.series !== undefined
  );
}

/**
 * The window, in BUSINESS-timezone Y-m-d dates (Indian/Maldives) — the same
 * grammar the reports take, so the two screens can be asked the same
 * question. 2026-08-01 means the Maldivian first of August: a sale at 02:00
 * Malé on the 1st belongs to August even though it was 31 July in UTC. Never
 * send an ISO instant; the API validates `date_format:Y-m-d`.
 *
 * BOTH DATES OR NEITHER — which is why this is one object rather than two
 * optional fields. Half a window is a 422 on the server ("from the 5th to
 * ...when?"), and the shape here makes that unrepresentable. Omit the
 * argument entirely for the default: the business month IN PROGRESS, the 1st
 * to today.
 *
 * At most REPORT_MAX_DAYS (366) days, the same ceiling the reports carry.
 */
export interface DashboardWindow {
  from: string;
  to: string;
}

/**
 * GET /api/admin/dashboard — every panel, in one call.
 *
 * `auth:admin`: 401 without an admin session (a merchant or customer session
 * is a 401 too, not a 403). A plain admin gets 200 with `can_view_money`
 * false and the money panels omitted — the superadmin gate is on those two
 * sections, never on the route, so the operational half of the console stays
 * open to every admin.
 *
 * A 422 means the window was refused: half a window, a backwards one, a
 * malformed date, or one longer than REPORT_MAX_DAYS.
 */
export function getAdminDashboard(
  period?: DashboardWindow | null,
  options: RequestOptions = {},
): Promise<AdminDashboard> {
  return apiFetch(
    `/api/admin/dashboard${queryString({
      from: period?.from,
      to: period?.to,
    })}`,
    AdminDashboardSchema,
    { signal: options.signal },
  );
}

/**
 * GET /api/admin/dashboard/attention — the attention counts on their own.
 *
 * The SAME six numbers `getAdminDashboard().attention` carries, from the same
 * predicates in the same single round trip, with no period: none of the six
 * queues is periodised, so a badge has no window to be asked about.
 *
 * THIS IS WHAT A NAV BADGE READS. Fetching the list behind a badge to pull
 * one scalar off it costs a paginated query per badge per poll and — because
 * each badge then has its own timer — lets a badge and the dashboard tile it
 * links to disagree, which is the one thing the attention panel is built to
 * make impossible. Give every badge the SAME query key so they share one
 * poll, and seed that key from `dashboard.attention` on the landing page so
 * the badge and the tile are literally the same read.
 *
 * `auth:admin`, and open to every admin: these are counts of work, never
 * money.
 */
export function getAdminAttention(
  options: RequestOptions = {},
): Promise<DashboardAttention> {
  return apiFetch("/api/admin/dashboard/attention", DashboardAttentionSchema, {
    signal: options.signal,
  });
}
