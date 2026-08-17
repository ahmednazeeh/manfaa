import { z } from 'zod';
import { apiBaseUrl, apiFetch, apiFetchBlob } from './client';
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
  type ClaimState,
  type PromotionStatus,
  type SettlementState,
} from './resources';

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
  return encoded === '' ? '' : `?${encoded}`;
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
    { method: 'POST', body, signal: options.signal },
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
    { method: 'POST', signal: options.signal },
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
    { method: 'POST', body, signal: options.signal },
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
  return apiFetch('/api/admin/merchants', MerchantStandingListResponseSchema, {
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
    { method: 'PUT', body: { featured }, signal: options.signal },
  );
}

export const MerchantNoticeTypeSchema = z.enum([
  'reminder_day10',
  'urgent_day13',
  'due_day15',
  'suspended',
  'reinstated',
  'write_off',
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
    { method: 'PATCH', body, signal: options.signal },
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
    { method: 'POST', body, signal: options.signal },
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
    { method: 'POST', body, signal: options.signal },
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
    { method: 'POST', signal: options.signal },
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
    { method: 'PATCH', body: { is_active: isActive }, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Customer accounts (superadmin support surface)
// ---------------------------------------------------------------------------

/** active | suspended | closed — the customers_status_check literals. */
export const CustomerStatusSchema = z.enum(['active', 'suspended', 'closed']);
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
    { method: 'PATCH', body, signal: options.signal },
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
    { method: 'POST', signal: options.signal },
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
  body: { status: 'active' | 'suspended'; reason?: string },
  options: RequestOptions = {},
): Promise<CustomerStatusChangeResponse> {
  return apiFetch(
    `/api/admin/customers/${customerId}/status`,
    CustomerStatusChangeResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Reconciliation runs
// ---------------------------------------------------------------------------

export const ReconciliationIssueSchema = z.discriminatedUnion('kind', [
  z.object({
    kind: z.literal('unbalanced_journal'),
    journal_id: z.number().int(),
    currency: z.string(),
    debit_laari: z.number().int(),
    credit_laari: z.number().int(),
  }),
  z.object({
    kind: z.literal('balance_mismatch'),
    account: z.string(),
    derived_laari: z.number().int(),
    ledger_laari: z.number().int(),
  }),
]);
export type ReconciliationIssue = z.infer<typeof ReconciliationIssueSchema>;

export const ReconciliationRunSchema = z.object({
  id: z.number().int(),
  ran_at: z.string(),
  status: z.enum(['ok', 'divergent']),
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
  return apiFetch('/api/admin/payout-batches', PayoutBatchResponseSchema, {
    method: 'POST',
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
    { method: 'POST', signal: options.signal },
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
    { method: 'POST', signal: options.signal },
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
    method: 'POST',
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
  body.append('file', file);
  return apiFetch(
    `/api/admin/payout-batches/${batchId}/import`,
    PayoutBatchResponseSchema,
    { method: 'POST', body, signal: options.signal },
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
      method: 'POST',
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
      method: 'POST',
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
      method: 'POST',
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
    { method: 'POST', body, signal: options.signal },
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
    { method: 'POST', body, signal: options.signal },
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
    '/api/admin/platform/bank-accounts',
    PlatformBankAccountListResponseSchema,
    { signal: options.signal },
  );
}

export const CreatePlatformBankAccountRequestSchema = z.object({
  bank_name: BankSlugSchema,
  account_no: z.string().min(1).max(255),
  account_name: z.string().min(1).max(255),
  /** MVR only in v1; defaults to MVR when omitted. */
  currency: z.literal('MVR').optional(),
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
    '/api/admin/platform/bank-accounts',
    PlatformBankAccountResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

export const UpdatePlatformBankAccountRequestSchema = z.object({
  bank_name: BankSlugSchema.optional(),
  account_no: z.string().min(1).max(255).optional(),
  account_name: z.string().min(1).max(255).optional(),
  currency: z.literal('MVR').optional(),
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
    { method: 'PATCH', body, signal: options.signal },
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
    '/api/admin/platform/fee-tiers',
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
    '/api/admin/platform/fee-tiers',
    FeeTierScheduleResponseSchema,
    { method: 'POST', body, signal: options.signal },
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
  'min_payout_laari',
  'settlement_due_days',
  'write_off_days',
  'default_validation_window_days',
  'default_min_eligible_laari',
  /**
   * PLAN §1 prompt-payment discount: the percent taken off the PLATFORM FEE
   * when a merchant settles everything outstanding promptly ("0.00" disables
   * it, "20.00" is the ceiling), and how young every line must be, in whole
   * days, to qualify. The window must stay shorter than
   * `settlement_due_days` or the incentive rewards nothing; the range (1–15)
   * enforces the outer bound.
   */
  'prompt_discount_rate_percent',
  'prompt_discount_max_age_days',
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
    '/api/admin/platform/settings',
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
    { method: 'PATCH', body, signal: options.signal },
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
  return apiFetch('/api/admin/platform/app-releases', AppReleasesResponseSchema, {
    signal: options.signal,
  });
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
  return apiFetch('/api/admin/platform/app-releases', AppReleasesResponseSchema, {
    method: 'PUT',
    body,
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Admin users (superadmin only)
// ---------------------------------------------------------------------------

export const AdminRoleSchema = z.enum(['admin', 'superadmin']);
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
  return apiFetch('/api/admin/admins', AdminUserListResponseSchema, {
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
  return apiFetch('/api/admin/admins', CreateAdminUserResponseSchema, {
    method: 'POST',
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
    method: 'PATCH',
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
  return apiFetch('/api/admin/zones', ZoneListResponseSchema, {
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
  return apiFetch('/api/admin/zones/order', ZoneListResponseSchema, {
    method: 'PUT',
    body: { ids },
    signal: options.signal,
  });
}

/** POST /api/admin/zones — 201; reassigns every pinned branch. */
export function createZone(
  body: ZoneInput,
  options: RequestOptions = {},
): Promise<ZoneResponse> {
  return apiFetch('/api/admin/zones', ZoneResponseSchema, {
    method: 'POST',
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
    method: 'PUT',
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
    method: 'DELETE',
    signal: options.signal,
  });
}
