import {
  apiFetch,
  bootstrapCsrf,
  bpToPercentString,
  CreateMerchantStaffResponseSchema,
  dataWrapped,
  FeeTreatmentSchema,
  MerchantAuthUserResponseSchema,
  MerchantAuthUserSchema,
  MerchantStaffResponseSchema,
  paginated,
  PercentSchema,
  RateDescriptionSchema,
  SettlementPaymentSchema,
  SettlementPreviewSchema,
  SettlementSchema,
  TransactionSchema,
  type CreateMerchantStaffResponse,
  type MerchantAuthUser,
  type MerchantStaffResponse,
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

/**
 * The signed-in account, passed through from the shared client rather than
 * described a second time here. A local copy is how the permission set goes
 * missing: zod strips what a schema does not declare, so a panel-side /me
 * schema that forgets `permissions` hands every gate `undefined` with
 * neither a parse error nor a type error, and every gate then denies.
 */
export { MerchantAuthUserSchema, type MerchantAuthUser };

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

/**
 * Stores whose PUBLIC CLAIMS are reviewed before they move (MR9) — the live
 * estate, i.e. active or suspended. Mirrors
 * ChangeRequestService::GATED_STATUSES: a store still onboarding writes
 * straight through the wizard, because the whole record is about to be
 * reviewed anyway.
 *
 * The screens use this only to SET EXPECTATIONS before a click ("Manfaa
 * reviews new branches"). Whether a given save actually queued is never
 * inferred here — the server answers 202 with the change request, and that
 * answer is what the panel reports.
 */
export function isReviewedStatus(status: MerchantStatus): boolean {
  return status === 'active' || status === 'suspended';
}

/** POST /api/merchant/auth/login — bootstraps the Sanctum CSRF cookie first. */
export async function login(body: {
  email: string;
  password: string;
}): Promise<MerchantAuthUser> {
  await bootstrapCsrf();
  const response = await apiFetch(
    '/api/merchant/auth/login',
    MerchantAuthUserResponseSchema,
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
export async function fetchMe(signal?: AbortSignal): Promise<MerchantAuthUser> {
  const response = await apiFetch(
    '/api/merchant/auth/me',
    MerchantAuthUserResponseSchema,
    { signal },
  );
  return response.data;
}

// ---------------------------------------------------------------------------
// GET /api/merchant/rate
// ---------------------------------------------------------------------------

/**
 * One merchant_rates window with its §4 cost picture — 2-decimal percent
 * strings on the wire (PLAN §1), basis points only inside the panel's own
 * arithmetic. The fee fields are null in exactly one degenerate case: a
 * legacy "stranded" rate the fee schedule in force does not price — the
 * panel must still render (it is the merchant's self-rescue path), showing
 * the fee as unknown.
 */
export const RateWindowSchema = RateDescriptionSchema.extend({
  platform_fee_percent: PercentSchema.nullable(),
  all_in_percent: PercentSchema.nullable(),
  effective_from: z.string(),
  effective_to: z.string().nullable(),
});
export type RateWindow = z.infer<typeof RateWindowSchema>;

/**
 * The GST terms a sale recorded RIGHT NOW would be stamped with — the one
 * FORWARD-LOOKING thing this endpoint publishes, and the only reason the
 * panel can quote a cost before a sale exists.
 *
 * Every other GST figure the panel reads is the tax STAMPED on a row that
 * already happened (`fee_gst_laari`, `fee_gst_percent`), which is right for
 * a receipt and useless for an estimate. The pre-record quote has no row to
 * read, so it prices from the live policy — exactly as the server will a
 * second later.
 *
 * `"0.00"` is the platform today and means no tax under either treatment.
 */
export const MerchantTaxTermsSchema = z.object({
  gst_rate_percent: PercentSchema,
  fee_treatment: FeeTreatmentSchema,
});
export type MerchantTaxTerms = z.infer<typeof MerchantTaxTermsSchema>;

/**
 * The standing rate as the panel sees it: the currently effective window
 * plus any scheduled (not-yet-applied) change. Either side is null when no
 * such window exists.
 */
export const MerchantRateSchema = z.object({
  current: RateWindowSchema.nullable(),
  pending: RateWindowSchema.nullable(),
  tax: MerchantTaxTermsSchema,
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
    platform_fee_percent: PercentSchema.nullable(),
    all_in_percent: PercentSchema.nullable(),
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
 * POST /api/merchant/rate — owner only (403 otherwise). The wire carries a
 * 2-decimal percent STRING (PLAN §1: `cashback_rate_percent`, never
 * `rate_bp`). The panel still works in integer basis points internally —
 * the merchant's typed percent goes through parsePercentToBp so the range
 * checks and the §4 tier-cliff comparison are integer arithmetic — and this
 * is the one place that turns that integer back into the wire's percent, by
 * exact string decomposition. A structurally legal rate the ACTIVE fee tier
 * schedule does not price answers 422 `code: rate_not_priced`.
 */
export function changeRate(rateBp: number): Promise<ChangeRateResponse> {
  return apiFetch('/api/merchant/rate', ChangeRateResponseSchema, {
    method: 'POST',
    body: { cashback_rate_percent: bpToPercentString(rateBp) },
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

/**
 * The two corrections a merchant may make to their own sale, while their
 * validation window is still open (the API refuses both afterwards, and
 * always on a backdated credit).
 */
export interface AmendTransactionBody {
  eligible_amount: number;
  sale_amount?: number | null;
  /** Replaces the split wholesale; omit to leave a single-rate sale alone. */
  lines?: Array<{ category: string | null; amount_laari: number }>;
}

const TransactionResponseSchema = dataWrapped(TransactionSchema);

/** PATCH /api/merchant/transactions/{id} — correct the amounts. */
export function amendTransaction(
  id: number,
  body: AmendTransactionBody,
): Promise<z.infer<typeof TransactionResponseSchema>> {
  return apiFetch(
    `/api/merchant/transactions/${id}`,
    TransactionResponseSchema,
    { method: 'PATCH', body },
  );
}

export type CancelReason = 'refund' | 'void' | 'duplicate' | 'error';

/** POST /api/merchant/transactions/{id}/cancel — take the sale back off. */
export function cancelTransaction(
  id: number,
  body: { reason: CancelReason; note?: string | null },
): Promise<z.infer<typeof TransactionResponseSchema>> {
  return apiFetch(
    `/api/merchant/transactions/${id}/cancel`,
    TransactionResponseSchema,
    { method: 'POST', body },
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
 * (none is quoted) — the real one is assigned at submit and is
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
  /** The bank's reference, when the merchant has it. Optional — the slip carries it. */
  bankRef?: string;
  slip: File;
  /**
   * WHICH platform account received it. Sent so the batch can be reconciled
   * against the right statement instead of against whichever account was
   * primary when the screen loaded.
   */
  platformBankAccountId?: number;
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
  if (receipt.bankRef !== undefined && receipt.bankRef !== '') {
    form.append('bank_ref', receipt.bankRef);
  }
  form.append('slip', receipt.slip);
  if (receipt.platformBankAccountId !== undefined) {
    form.append(
      'platform_bank_account_id',
      String(receipt.platformBankAccountId),
    );
  }
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
  if (receipt.bankRef !== undefined && receipt.bankRef !== '') {
    form.append('bank_ref', receipt.bankRef);
  }
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
// Staff — MR8 employee-management completeness
// ---------------------------------------------------------------------------

/**
 * PATCH /api/merchant/staff/{id}, widened past the shared client's
 * role/activation pair: MR8 added NAME and EMAIL edits to the same route.
 * The email keeps the invite's global-unique rule (ignoring only the
 * target's own row), so a duplicate refuses 422 exactly like a duplicate
 * invite — the server's message is shown as-is. Every guard on the
 * role/active pair (last owner, self) is unchanged.
 */
export interface UpdateStaffBody {
  merchant_role_id?: number;
  is_active?: boolean;
  name?: string;
  email?: string;
}

export function updateStaff(
  id: number,
  body: UpdateStaffBody,
): Promise<MerchantStaffResponse> {
  return apiFetch(`/api/merchant/staff/${id}`, MerchantStaffResponseSchema, {
    method: 'PATCH',
    body,
  });
}

/**
 * POST /api/merchant/staff/{id}/reset-password (MR8) — a fresh
 * server-generated temporary password, returned EXACTLY ONCE at the root
 * beside `data`, byte-for-byte the invite's shape (hence the shared
 * response schema, not a restatement). Everything the old password
 * unlocked dies with it server-side: the target's panel sessions, every
 * mobile token, the remember-me cookie. Self-reset is allowed — the
 * caller's own session then dies on its next request, so the reveal
 * dialog is the last thing they do signed in.
 */
export function resetStaffPassword(
  id: number,
): Promise<CreateMerchantStaffResponse> {
  return apiFetch(
    `/api/merchant/staff/${id}/reset-password`,
    CreateMerchantStaffResponseSchema,
    { method: 'POST' },
  );
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
