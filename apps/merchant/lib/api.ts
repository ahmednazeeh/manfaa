import {
  apiFetch,
  bootstrapCsrf,
  dataWrapped,
  MerchantStaffRoleSchema,
  MerchantStatusSchema,
  paginated,
  RateDescriptionSchema,
  SettlementPaymentSchema,
  SettlementPreviewSchema,
  SettlementSchema,
  TransactionSchema,
  type MerchantStatus,
  type SettlementBankAccount,
  type SettlementPreview,
  type TransactionState,
} from '@manfaa/api-client';
import { z } from 'zod';

/**
 * Merchant-panel API surface not covered by the shared client: session auth
 * against /api/merchant/auth and the filterable transactions table. All
 * amounts are integer laari; *_mvr strings are display-only.
 */

export const MerchantMeSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.email(),
  /** owner | manager | staff — the panel gates its nav and screens on it. */
  role: MerchantStaffRoleSchema,
  merchant: z.object({
    id: z.number().int(),
    name: z.string(),
    /**
     * The onboarding lifecycle — the panel routes draft/rejected/
     * pending_review users onto /setup and keeps the rest of the panel
     * inaccessible until the store is approved.
     */
    status: MerchantStatusSchema,
  }),
});
export type MerchantMe = z.infer<typeof MerchantMeSchema>;

/**
 * Statuses that belong on /setup (the wizard or its waiting/rejection
 * screens) instead of the panel: draft (mid-wizard), pending_review
 * (submitted, awaiting the admin queue) and rejected (sent back).
 */
export function isOnboardingStatus(status: MerchantStatus): boolean {
  return (
    status === 'draft' || status === 'pending_review' || status === 'rejected'
  );
}

const MeResponseSchema = dataWrapped(MerchantMeSchema);

/** POST /api/merchant/auth/login — bootstraps the Sanctum CSRF cookie first. */
export async function login(body: {
  email: string;
  password: string;
}): Promise<MerchantMe> {
  await bootstrapCsrf();
  const response = await apiFetch(
    '/api/merchant/auth/login',
    MeResponseSchema,
    {
      method: 'POST',
      body,
    },
  );
  return response.data;
}

/** POST /api/merchant/auth/logout — 204 on success. */
export async function logout(): Promise<void> {
  await apiFetch('/api/merchant/auth/logout', z.void(), { method: 'POST' });
}

/** GET /api/merchant/auth/me — 401 when the session is gone. */
export async function fetchMe(signal?: AbortSignal): Promise<MerchantMe> {
  const response = await apiFetch('/api/merchant/auth/me', MeResponseSchema, {
    signal,
  });
  return response.data;
}

// ---------------------------------------------------------------------------
// GET /api/merchant/rate
// ---------------------------------------------------------------------------

/**
 * One merchant_rates window with its §4 cost picture (integer basis
 * points). The fee fields are null in exactly one degenerate case: a legacy
 * "stranded" rate the fee schedule in force does not price — the panel must
 * still render (it is the merchant's self-rescue path), showing the fee as
 * unknown.
 */
export const RateWindowSchema = RateDescriptionSchema.extend({
  fee_bp: z.number().int().nullable(),
  all_in_bp: z.number().int().nullable(),
  effective_from: z.string(),
  effective_to: z.string().nullable(),
});
export type RateWindow = z.infer<typeof RateWindowSchema>;

/**
 * The standing rate as the panel sees it: the currently effective window
 * plus any scheduled (not-yet-applied) change. Either side is null when no
 * such window exists.
 */
export const MerchantRateSchema = z.object({
  current: RateWindowSchema.nullable(),
  pending: RateWindowSchema.nullable(),
});
export type MerchantRate = z.infer<typeof MerchantRateSchema>;

const RateResponseSchema = dataWrapped(MerchantRateSchema);

/** GET /api/merchant/rate — readable by any merchant user (owner or staff). */
export async function fetchRate(signal?: AbortSignal): Promise<MerchantRate> {
  const response = await apiFetch('/api/merchant/rate', RateResponseSchema, {
    signal,
  });
  return response.data;
}

// ---------------------------------------------------------------------------
// POST /api/merchant/rate (owner only)
// ---------------------------------------------------------------------------

/**
 * The §7 change summary returned alongside the refreshed rate state: both
 * sides priced under the fee tier schedule in force at effective_at, so the
 * tier-cliff warning fires on the ACTUAL fee boundaries. `previous` echoes
 * the new rate when the merchant had no standing rate before.
 */
export const RateChangeSummarySchema = z.object({
  /**
   * The outgoing side carries null fee fields when that rate was never
   * priced by the schedule at effective_at (a stranded rate being rescued
   * by this very change).
   */
  previous: RateDescriptionSchema.extend({
    fee_bp: z.number().int().nullable(),
    all_in_bp: z.number().int().nullable(),
  }),
  new: RateDescriptionSchema,
  /** ISO 8601 in the business timezone. */
  effective_at: z.string(),
  /** §7: increases apply immediately, decreases at next business midnight. */
  applies: z.enum(['immediately', 'next_business_midnight']),
  tier_changed: z.boolean(),
});
export type RateChangeSummary = z.infer<typeof RateChangeSummarySchema>;

export const ChangeRateResponseSchema = z.object({
  data: MerchantRateSchema,
  change: RateChangeSummarySchema,
});
export type ChangeRateResponse = z.infer<typeof ChangeRateResponseSchema>;

/**
 * POST /api/merchant/rate — owner only (403 otherwise). The rate is sent as
 * integer basis points; the panel converts the merchant's exact-2dp percent
 * input with the shared parsePercentToBp helper (string decomposition, no
 * floats). A structurally legal rate the ACTIVE fee tier schedule does not
 * price answers 422 `code: rate_not_priced`.
 */
export function changeRate(rateBp: number): Promise<ChangeRateResponse> {
  return apiFetch('/api/merchant/rate', ChangeRateResponseSchema, {
    method: 'POST',
    body: { rate_bp: rateBp },
  });
}

// ---------------------------------------------------------------------------
// GET /api/merchant/transactions
// ---------------------------------------------------------------------------

const TransactionListResponseSchema = paginated(TransactionSchema);
export type TransactionListResponse = z.infer<
  typeof TransactionListResponseSchema
>;

export interface TransactionListParams {
  state?: TransactionState;
  page?: number;
}

/** GET /api/merchant/transactions — newest first, optional state filter. */
export function listTransactions(
  params: TransactionListParams = {},
  signal?: AbortSignal,
): Promise<TransactionListResponse> {
  const query = new URLSearchParams();
  if (params.state !== undefined) query.set('state', params.state);
  if (params.page !== undefined) query.set('page', String(params.page));
  const suffix = query.size > 0 ? `?${query.toString()}` : '';
  return apiFetch(
    `/api/merchant/transactions${suffix}`,
    TransactionListResponseSchema,
    { signal },
  );
}

// ---------------------------------------------------------------------------
// Receipt-first settlements (PLAN §1 "Settlement flow")
// ---------------------------------------------------------------------------

/**
 * The merchant settlement surface is receipt-first: preview a selection,
 * transfer at the bank, then SUBMIT the slip — the single act that creates a
 * settlement at all, landing it in payment_review. There is no draft to
 * submit and no merchant route to awaiting_payment, so no path in this panel
 * can reach a settlement without a receipt.
 *
 * It lives here rather than in @manfaa/api-client because these responses
 * carry merchant-only fields the shared Settlement schema does not model
 * (`merchant_status` and the receipt columns on a payment); a zod object
 * strips what it does not declare, so the panel declares them itself.
 */

/** What to settle: everything eligible, or exactly these transactions. */
export type SettlementSelection =
  { settleAll: true } | { transactionIds: number[] };

function appendSelection(
  append: (key: string, value: string) => void,
  selection: SettlementSelection,
): void {
  if ('settleAll' in selection) {
    append('settle_all', '1');
    return;
  }
  for (const id of selection.transactionIds) {
    append('transaction_ids[]', String(id));
  }
}

/**
 * The platform's payout account. An alias of the shared contract rather than
 * a local restatement — the panel's copy was identical field for field.
 */
export type PlatformBankAccount = SettlementBankAccount;

/**
 * GET /api/merchant/settlements/preview — what this selection costs and
 * where to send it, BEFORE any commitment. Reservation-free: no batch, no
 * lines frozen, and the reference is explicitly a preview
 * (`reference_is_final: false`) — the real one is assigned at submit and is
 * the one the merchant should quote if they ever differ.
 *
 * The response is parsed against the SHARED schema (@manfaa/api-client), not
 * a copy kept here: it carries the picker's row set, the age-preset buckets
 * and the PLAN §1 discount evaluation, and a second declaration of those is
 * only ever a place for the panel and the API to drift apart. (The
 * merchant-only extras below — `merchant_status`, the receipt columns — do
 * still need their own schemas; the preview never did.)
 */
const SettlementPreviewResponseSchema = dataWrapped(SettlementPreviewSchema);

export type { SettlementPreview };

export function previewSettlement(
  selection: SettlementSelection,
  signal?: AbortSignal,
): Promise<SettlementPreview> {
  const query = new URLSearchParams();
  appendSelection((key, value) => query.append(key, value), selection);
  return apiFetch(
    `/api/merchant/settlements/preview?${query.toString()}`,
    SettlementPreviewResponseSchema,
    { signal },
  ).then((response) => response.data);
}

/**
 * The human answer to "what is happening to my transfer" (the raw §6 state
 * stays in `state` for machines) — including, when an admin refused the
 * receipt, WHY, so the merchant can fix it and submit a new settlement.
 */
export const SettlementMerchantStatusSchema = z.object({
  code: z.enum([
    'draft',
    'awaiting_payment',
    'verifying',
    'settled',
    'partially_settled',
    'rejected',
    'cancelled',
  ]),
  message: z.string(),
  rejection: z
    .object({
      reason: z.string().nullable(),
      rejected_at: z.string().nullable(),
      bank_ref: z.string().nullable(),
      payment_id: z.number().int(),
    })
    .nullable(),
});
export type SettlementMerchantStatus = z.infer<
  typeof SettlementMerchantStatusSchema
>;

/**
 * A payment with its receipt columns. `has_slip` is what the UI branches on
 * — `slip_path` is a private-disk path (storage/app/slips), never fetchable;
 * only an admin streams a slip, through their own authenticated route.
 */
export const ReceiptPaymentSchema = SettlementPaymentSchema.extend({
  has_slip: z.boolean(),
  slip_mime: z.string().nullable(),
  slip_size_bytes: z.number().int().nullable(),
  uploaded_by: z.number().int().nullable(),
  rejected_by: z.number().int().nullable(),
  rejected_at: z.string().nullable(),
  rejection_reason: z.string().nullable(),
});
export type ReceiptPayment = z.infer<typeof ReceiptPaymentSchema>;

export const MerchantSettlementSchema = SettlementSchema.extend({
  merchant_status: SettlementMerchantStatusSchema,
  payments: z.array(ReceiptPaymentSchema).optional(),
});
export type MerchantSettlement = z.infer<typeof MerchantSettlementSchema>;

const MerchantSettlementListSchema = paginated(MerchantSettlementSchema);
export type MerchantSettlementList = z.infer<
  typeof MerchantSettlementListSchema
>;
const MerchantSettlementResponseSchema = dataWrapped(MerchantSettlementSchema);

/** GET /api/merchant/settlements — newest first, 25 per page. */
export function listSettlements(
  page: number,
  signal?: AbortSignal,
): Promise<MerchantSettlementList> {
  return apiFetch(
    `/api/merchant/settlements?page=${page}`,
    MerchantSettlementListSchema,
    { signal },
  );
}

/** GET /api/merchant/settlements/{id} — lines (with transaction) + payments. */
export function getSettlement(
  id: number,
  signal?: AbortSignal,
): Promise<MerchantSettlement> {
  return apiFetch(
    `/api/merchant/settlements/${id}`,
    MerchantSettlementResponseSchema,
    { signal },
  ).then((response) => response.data);
}

export interface ReceiptSubmission {
  /** Laari actually transferred — normally the previewed amount due. */
  amountLaari: number;
  bankRef: string;
  slip: File;
}

/**
 * POST /api/merchant/settlements (multipart) — builds the batch, freezes its
 * lines, stores the slip privately and records the claimed payment in one
 * server-side transaction. 201 with the settlement in payment_review.
 */
export function submitSettlementWithReceipt(
  selection: SettlementSelection,
  receipt: ReceiptSubmission,
): Promise<MerchantSettlement> {
  const form = new FormData();
  appendSelection((key, value) => form.append(key, value), selection);
  form.append('amount', String(receipt.amountLaari));
  form.append('bank_ref', receipt.bankRef);
  form.append('slip', receipt.slip);
  return apiFetch(
    '/api/merchant/settlements',
    MerchantSettlementResponseSchema,
    {
      method: 'POST',
      body: form,
    },
  ).then((response) => response.data);
}

/**
 * POST /api/merchant/settlements/{id}/receipts (multipart) — a FURTHER
 * transfer against a batch still owed money: the remainder after a §7
 * partial payment (whose uncovered lines stay frozen on this batch), or the
 * transfer for a batch an admin built as the fallback.
 */
export function addSettlementReceipt(
  id: number,
  receipt: ReceiptSubmission,
): Promise<MerchantSettlement> {
  const form = new FormData();
  form.append('amount', String(receipt.amountLaari));
  form.append('bank_ref', receipt.bankRef);
  form.append('slip', receipt.slip);
  return apiFetch(
    `/api/merchant/settlements/${id}/receipts`,
    MerchantSettlementResponseSchema,
    { method: 'POST', body: form },
  ).then((response) => response.data);
}

/**
 * POST /api/merchant/settlements/wallet — §7's "same path, different funding
 * source": build and settle from the wallet balance in one call. No receipt,
 * because no bank transfer happened — the top-up that funded the wallet is
 * the evidence.
 */
export function walletSettleSelection(
  selection: SettlementSelection,
): Promise<MerchantSettlement> {
  const body: Record<string, unknown> =
    'settleAll' in selection
      ? { settle_all: true }
      : { transaction_ids: selection.transactionIds };
  return apiFetch(
    '/api/merchant/settlements/wallet',
    MerchantSettlementResponseSchema,
    { method: 'POST', body },
  ).then((response) => response.data);
}

// ---------------------------------------------------------------------------
// Slip pre-flight (mirrors the server's SlipStorage rules)
// ---------------------------------------------------------------------------

/** 5 MB — SlipStorage::MAX_BYTES. The server refuses anything larger. */
export const MAX_SLIP_BYTES = 5 * 1024 * 1024;

/**
 * What the SERVER accepts, decided there by magic bytes. This list is the
 * courtesy check that saves a 5 MB round trip; it is never the authority —
 * a renamed .svg passes here and is still refused by the API.
 */
export const ACCEPTED_SLIP_TYPES = [
  'image/jpeg',
  'image/png',
  'image/webp',
  'application/pdf',
] as const;

/** The `accept` attribute for the file picker, matching the above. */
export const SLIP_ACCEPT_ATTRIBUTE =
  '.jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf';

const SLIP_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

export type SlipRejection = 'too_large' | 'unsupported_type';

/**
 * Client-side pre-flight on a chosen slip: size first (a 40 MB photo is
 * refused before it is read), then the type — by MIME when the browser
 * supplies one, otherwise by extension. Returns null when the file looks
 * acceptable.
 */
export function checkSlipFile(file: File): SlipRejection | null {
  if (file.size > MAX_SLIP_BYTES) return 'too_large';
  if (file.size <= 0) return 'unsupported_type';

  const mime = file.type.toLowerCase();
  if (mime !== '') {
    return (ACCEPTED_SLIP_TYPES as readonly string[]).includes(mime)
      ? null
      : 'unsupported_type';
  }

  const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
  return SLIP_EXTENSIONS.includes(extension) ? null : 'unsupported_type';
}
