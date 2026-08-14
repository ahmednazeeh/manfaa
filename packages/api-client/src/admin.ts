import { z } from 'zod';
import { apiFetch, apiFetchText } from './client';
import {
  ClaimStateSchema,
  dataWrapped,
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
 * lifecycle (Phase 1), and the claims queue and promotions read model
 * (Phase 3). All amounts sent and received are integer laari.
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

// ---------------------------------------------------------------------------
// Merchant standing + notices
// ---------------------------------------------------------------------------

export const MerchantStandingSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  slug: z.string(),
  status: z.string(),
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
