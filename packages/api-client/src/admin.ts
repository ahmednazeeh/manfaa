import { z } from 'zod';
import { apiBaseUrl, apiFetch, apiFetchBlob, apiFetchText } from './client';
import {
  ClaimStateSchema,
  dataWrapped,
  MerchantStatusSchema,
  paginated,
  PayoutBatchSchema,
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
 * schedule, typed platform settings, and superadmin-only admin account
 * management. All amounts sent and received are integer laari; all rates are
 * integer basis points.
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
  year: z.number().int().min(2020).max(2100),
  month: z.number().int().min(1).max(12),
});
export type CreatePayoutBatchRequest = z.infer<
  typeof CreatePayoutBatchRequestSchema
>;

/** POST /api/admin/payout-batches — builds a draft for the month (201), items loaded. */
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

/** POST /api/admin/payout-batches/{batch}/approve — one of the two approvals. */
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
 * POST /api/admin/payout-batches/{batch}/export — exports the bank-file CSV.
 *
 * Deliberately a POST, not a link target: the first export mutates state
 * (approved → processing, items → sent), so it must never be reachable by a
 * GET a browser could prefetch or a cross-site navigation could trigger.
 * Returns the CSV text; hand it to the user as a Blob download. While the
 * batch is processing and no bank result has been imported, calling again
 * re-downloads the identical file.
 */
export function exportAdminPayoutBatch(
  batchId: number,
  options: RequestOptions = {},
): Promise<string> {
  return apiFetchText(`/api/admin/payout-batches/${batchId}/export`, {
    method: 'POST',
    signal: options.signal,
  });
}

/** POST /api/admin/payout-batches/{batch}/import — uploads the bank's result CSV. */
export function importAdminPayoutResults(
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
  bank_name: z.string().min(1).max(255),
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
  bank_name: z.string().min(1).max(255).optional(),
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

/** One {from_bp, to_bp, fee_bp} band — all integer basis points. */
export const FeeTierBandSchema = z.object({
  from_bp: z.number().int(),
  to_bp: z.number().int(),
  fee_bp: z.number().int(),
});
export type FeeTierBand = z.infer<typeof FeeTierBandSchema>;

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
   * Must be ascending, contiguous, cover exactly 50–1000 bp, with every
   * band's fee_bp a positive integer no greater than its from_bp.
   */
  tiers: z.array(FeeTierBandSchema).min(1),
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
 * One typed platform setting: the effective integer value, the hardcoded
 * default it falls back to, and the allowed range. All monetary values are
 * integer laari.
 */
export const PlatformSettingSchema = z.object({
  value: z.number().int(),
  default: z.number().int(),
  min: z.number().int(),
  max: z.number().int(),
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
   * PLAN §1 prompt-payment discount: basis points off the PLATFORM FEE when a
   * merchant settles everything outstanding promptly (0 disables it, 2000 is
   * the ceiling), and how young every line must be, in whole days, to
   * qualify. The window must stay shorter than `settlement_due_days` or the
   * incentive rewards nothing; the range (1–15) enforces the outer bound.
   */
  'prompt_discount_rate_bp',
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
  /** Integer; validated server-side against the key's allowed range (422). */
  value: z.number().int(),
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
