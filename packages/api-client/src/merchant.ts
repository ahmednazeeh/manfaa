import { z } from 'zod';
import { ApiError, apiFetch } from './client';
import {
  CashbackPercentInputSchema,
  dataWrapped,
  MerchantChannelSchema,
  MerchantStatusSchema,
  paginated,
  PercentDeltaSchema,
  PercentSchema,
  ProductCategoryModeSchema,
  PromotionSchema,
  PromotionStatusSchema,
  PromptDiscountReasonSchema,
  RateDescriptionSchema,
  SettlementBankAccountSchema,
  SettlementFundingMethodSchema,
  SettlementSchema,
  TransactionSchema,
  WalletSchema,
  type PromotionStatus,
} from './resources';

/**
 * Typed contracts for the merchant surface: outstanding by age bucket, the
 * settlement builder and lifecycle, the wallet, manual credits (Phase 1,
 * now with the optional lines[] split of Task #25), product-category CRUD,
 * the promotion builder (Phase 3), and the settings module (profile, bank
 * account, branches, staff, preferences, customer lookup). All amounts sent
 * and received are integer laari.
 *
 * Settings routes are OWNER-only (403 code `owner_required` for staff) —
 * except the customer lookup, which stays staff-accessible because posting
 * credits is staff work.
 *
 * Every settlement now carries `payment_instructions` — the platform's
 * active primary bank account embedded as `bank_account` (or null with
 * `needs_configuration: true` when the platform has not configured one),
 * plus the exact amount and reference to quote on the transfer. See
 * SettlementPaymentInstructionsSchema in resources.ts.
 *
 * Settlements are RECEIPT-FIRST (PLAN §1): preview → transfer at the bank →
 * submit the slip and bank reference, which is the single act that creates
 * the batch, landing it in `payment_review`. There is no draft-then-submit
 * pair and no merchant route to `awaiting_payment` — a settlement without a
 * receipt cannot be created. Settlement MUTATIONS need manager or above plus
 * an approved store; the preview and the reads stay staff-accessible.
 */

interface RequestOptions {
  signal?: AbortSignal;
}

// ---------------------------------------------------------------------------
// GET /api/merchant/outstanding
// ---------------------------------------------------------------------------

export const OutstandingBucketSchema = z.object({
  count: z.number().int(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  payable_laari: z.number().int(),
  payable_mvr: z.string(),
});
export type OutstandingBucket = z.infer<typeof OutstandingBucketSchema>;

export const OutstandingTotalSchema = OutstandingBucketSchema.extend({
  cashback_mvr: z.string(),
  fee_mvr: z.string(),
});
export type OutstandingTotal = z.infer<typeof OutstandingTotalSchema>;

/**
 * §7 reversal credits not yet netted into a batch. `credit_laari` is the
 * stored (NEGATIVE) sum of the pending adjustments — the amount that will
 * come OFF the next settlement, which is why the dashboard's outstanding
 * total and the next batch's amount due differ.
 */
export const PendingAdjustmentsSchema = z.object({
  count: z.number().int(),
  credit_laari: z.number().int(),
  credit_mvr: z.string(),
});
export type PendingAdjustments = z.infer<typeof PendingAdjustmentsSchema>;

export const OutstandingSummarySchema = z.object({
  as_of: z.string(),
  total: OutstandingTotalSchema,
  buckets: z.object({
    '0_5': OutstandingBucketSchema,
    '6_10': OutstandingBucketSchema,
    '11_15': OutstandingBucketSchema,
    overdue: OutstandingBucketSchema,
  }),
  pending_adjustments: PendingAdjustmentsSchema,
});
export type OutstandingSummary = z.infer<typeof OutstandingSummarySchema>;

export const OutstandingResponseSchema = dataWrapped(OutstandingSummarySchema);
export type OutstandingResponse = z.infer<typeof OutstandingResponseSchema>;

export function getMerchantOutstanding(
  options: RequestOptions = {},
): Promise<OutstandingResponse> {
  return apiFetch('/api/merchant/outstanding', OutstandingResponseSchema, {
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Settlements
// ---------------------------------------------------------------------------

export const MerchantSettlementListResponseSchema = paginated(SettlementSchema);
export type MerchantSettlementListResponse = z.infer<
  typeof MerchantSettlementListResponseSchema
>;

export const MerchantSettlementResponseSchema = dataWrapped(SettlementSchema);
export type MerchantSettlementResponse = z.infer<
  typeof MerchantSettlementResponseSchema
>;

/** GET /api/merchant/settlements — newest first, 25 per page. */
export function listMerchantSettlements(
  params: { page?: number } = {},
  options: RequestOptions = {},
): Promise<MerchantSettlementListResponse> {
  const query =
    params.page !== undefined ? `?page=${encodeURIComponent(params.page)}` : '';
  return apiFetch(
    `/api/merchant/settlements${query}`,
    MerchantSettlementListResponseSchema,
    { signal: options.signal },
  );
}

/** GET /api/merchant/settlements/{id} — with lines (incl. transaction) and payments. */
export function getMerchantSettlement(
  id: number,
  options: RequestOptions = {},
): Promise<MerchantSettlementResponse> {
  return apiFetch(
    `/api/merchant/settlements/${id}`,
    MerchantSettlementResponseSchema,
    { signal: options.signal },
  );
}

/**
 * Which payable transactions a settlement covers: everything eligible, or an
 * explicit list. An explicit list is claimed exactly — naming a transaction
 * that is not payable (already on a batch, not yet payable, reversed) is a
 * 422, never a silent drop.
 */
export type SettlementSelection =
  { settle_all: true } | { transaction_ids: number[] };

export const SettlementSelectionSchema = z.union([
  z.object({ settle_all: z.literal(true) }),
  z.object({ transaction_ids: z.array(z.number().int()).min(1) }),
]);

/** `?settle_all=1` or repeated `?transaction_ids[]=` — Laravel array syntax. */
function selectionQuery(selection: SettlementSelection): string {
  const search = new URLSearchParams();
  if ('settle_all' in selection) {
    search.set('settle_all', '1');
  } else {
    for (const id of selection.transaction_ids) {
      search.append('transaction_ids[]', String(id));
    }
  }
  return `?${search.toString()}`;
}

/** The same selection, as multipart fields on a receipt submission. */
function appendSelection(form: FormData, selection: SettlementSelection): void {
  if ('settle_all' in selection) {
    form.append('settle_all', '1');
  } else {
    for (const id of selection.transaction_ids) {
      form.append('transaction_ids[]', String(id));
    }
  }
}

/**
 * What a selection would cost and where to send it — the preview's own
 * payment instructions. The reference is a PREVIEW (`reference_is_final`
 * false): nothing is reserved, and the batch's real reference is assigned at
 * submit. If the two ever differ, the one on the created settlement is the
 * one the merchant must quote.
 */
export const SettlementPreviewInstructionsSchema = z.object({
  reference_preview: z.string(),
  reference_is_final: z.boolean(),
  amount_due_laari: z.number().int(),
  amount_due_mvr: z.string(),
  bank_account: SettlementBankAccountSchema.nullable(),
  needs_configuration: z.boolean(),
});
export type SettlementPreviewInstructions = z.infer<
  typeof SettlementPreviewInstructionsSchema
>;

/**
 * One selectable transaction in the settlement picker. Every figure is the
 * SERVER's: `due_laari` is the sum of the stored line integers, and
 * `age_days` is whole business-timezone days since `clock_start_at` (§13) —
 * the same count the discount window and the age buckets use. A panel may
 * total these rows to keep checkbox-clicking instant, but the amount a
 * merchant transfers is always one the API returned.
 */
export const SettlementPickerRowSchema = z.object({
  id: z.number().int(),
  invoice_no: z.string().nullable(),
  occurred_at: z.string().nullable(),
  /** Day 0 of the 15-day clock — when the validation window closed. */
  clock_start_at: z.string().nullable(),
  due_at: z.string().nullable(),
  age_days: z.number().int(),
  overdue: z.boolean(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  due_laari: z.number().int(),
  due_mvr: z.string(),
  /** Whether this row is in the selection this response priced. */
  selected: z.boolean(),
});
export type SettlementPickerRow = z.infer<typeof SettlementPickerRowSchema>;

/** Counts, totals and membership for one filter preset. */
export const SettlementPickerBucketSchema = z.object({
  count: z.number().int(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  due_laari: z.number().int(),
  due_mvr: z.string(),
  /** Send these back as a selection to apply the preset. */
  transaction_ids: z.array(z.number().int()),
});
export type SettlementPickerBucket = z.infer<
  typeof SettlementPickerBucketSchema
>;

/**
 * The picker's filter presets, over everything eligible (not just the
 * selection). Ages are whole days since `clock_start_at`, so `older_than_5`
 * is day 6 and beyond — the same boundary as the dashboard's 0–5 / 6–10
 * buckets. `overdue` is measured against `due_at`, independent of age.
 */
export const SettlementPickerBucketsSchema = z.object({
  all: SettlementPickerBucketSchema,
  older_than_5: SettlementPickerBucketSchema,
  older_than_10: SettlementPickerBucketSchema,
  overdue: SettlementPickerBucketSchema,
});
export type SettlementPickerBuckets = z.infer<
  typeof SettlementPickerBucketsSchema
>;

/**
 * The PLAN §1 prompt-payment discount as the preview sees it — ADVISORY.
 * The server re-decides it at submit, under the row lock, and that answer is
 * the one that moves money: a clock ticking past midnight or a till POSTing
 * one more sale between preview and submit legitimately withdraws it. Never
 * send this back; there is no field for it on the submission.
 *
 * `discount_laari` is 5% (`rate_percent`) of the FEE total, ceiling, and the
 * customer's cashback is never reduced. `reason_code` is set on refusals
 * too, so the panel can say what settling everything today would save.
 */
export const SettlementPreviewDiscountSchema = z.object({
  eligible: z.boolean(),
  reason_code: PromptDiscountReasonSchema,
  /**
   * The platform's configured rate as a 2-decimal percent string ("5.00"),
   * reported even when nothing was granted.
   */
  rate_percent: PercentSchema,
  /** How young every line must be, in whole days, to qualify. */
  max_age_days: z.number().int(),
  discount_laari: z.number().int(),
  discount_mvr: z.string(),
  /** The fee leg alone; equal to discount_laari while fee GST is zero. */
  fee_discount_laari: z.number().int(),
  /** GST recomputed proportionally on the discounted fee — zero today. */
  gst_relief_laari: z.number().int(),
});
export type SettlementPreviewDiscount = z.infer<
  typeof SettlementPreviewDiscountSchema
>;

/**
 * The receipt-first preview (PLAN §1): exactly what this selection will owe,
 * where to transfer it, and what to quote — before anything is claimed. No
 * draft is created and no reference is burnt, so previewing twice changes
 * nothing and the transactions stay eligible.
 *
 * `line_total_laari` is the batch before §7 credits; `credit_applied_laari`
 * is what pending reversal memos net off it (strict FIFO, stopping at the
 * first memo larger than what remains); `discount_laari` is the PLAN §1
 * prompt-payment discount; `amount_due_laari` is the transfer, net of both.
 *
 * It also feeds the picker: `transactions` is EVERY eligible row (not only
 * the selection — the list survives a re-price), and `buckets` carries the
 * counts, totals and ids behind the filter presets.
 */
export const SettlementPreviewSchema = z.object({
  /** When the server evaluated the ages and the discount. */
  as_of: z.string(),
  /** Exactly the transactions the batch would freeze, oldest due first. */
  transaction_ids: z.array(z.number().int()),
  transaction_count: z.number().int(),
  sale_total_laari: z.number().int(),
  cashback_total_laari: z.number().int(),
  fee_total_laari: z.number().int(),
  fee_gst_total_laari: z.number().int(),
  line_total_laari: z.number().int(),
  credit_applied_laari: z.number().int(),
  credit_applied_mvr: z.string(),
  discount_laari: z.number().int(),
  discount_mvr: z.string(),
  /** What the batch would owe with no discount — the "before" price. */
  amount_due_before_discount_laari: z.number().int(),
  amount_due_laari: z.number().int(),
  amount_due_mvr: z.string(),
  /** The EARLIEST line's due date (§7), not a batch creation date. */
  due_at: z.string().nullable(),
  discount: SettlementPreviewDiscountSchema,
  transactions: z.array(SettlementPickerRowSchema),
  buckets: SettlementPickerBucketsSchema,
  payment_instructions: SettlementPreviewInstructionsSchema,
});
export type SettlementPreview = z.infer<typeof SettlementPreviewSchema>;

export const SettlementPreviewResponseSchema = dataWrapped(
  SettlementPreviewSchema,
);
export type SettlementPreviewResponse = z.infer<
  typeof SettlementPreviewResponseSchema
>;

/**
 * GET /api/merchant/settlements/preview — staff-readable (it claims
 * nothing). 422 when the selection names a transaction that is not eligible,
 * or when there is nothing to settle at all.
 */
export function previewMerchantSettlement(
  selection: SettlementSelection,
  options: RequestOptions = {},
): Promise<SettlementPreviewResponse> {
  return apiFetch(
    `/api/merchant/settlements/preview${selectionQuery(selection)}`,
    SettlementPreviewResponseSchema,
    { signal: options.signal },
  );
}

/**
 * The receipt half of a submission: what was actually transferred, the bank's
 * own reference for it, and the slip.
 *
 * `slip` is validated by its BYTES server-side — JPEG, PNG, WebP or PDF, max
 * 5 MB. A renamed SVG or an HTML page called .pdf is refused (422
 * `slip_unsupported_type`), whatever the filename or the browser's declared
 * type says.
 */
export interface SettlementReceiptInput {
  /** Integer laari actually transferred, >= 1 — not necessarily the amount due. */
  amount: number;
  /** The bank's reference for the transfer; unique per settlement. */
  bank_ref: string;
  slip: File | Blob;
}

/** 5 MB — the server's own slip ceiling, so the panel can refuse first. */
export const SETTLEMENT_SLIP_MAX_BYTES = 5 * 1024 * 1024;

/** What the server accepts as a slip, by content — SVG is deliberately absent. */
export const SETTLEMENT_SLIP_ACCEPT =
  'image/jpeg,image/png,image/webp,application/pdf';

/**
 * Refusal codes carried on ApiError bodies as `code` for the receipt-first
 * routes (read them with `apiErrorCode`):
 *  - `slip_too_large` (422) — over 5 MB;
 *  - `slip_unsupported_type` (422) — the BYTES are not JPEG/PNG/WebP/PDF;
 *  - `duplicate_bank_ref` (409) — that reference is already recorded on this
 *    batch; the transfer is in the system and re-recording it would book the
 *    same cash twice;
 *  - `manager_required` (403) — submitting is a manager's job (staff read);
 *  - `store_not_approved` (403) — the store has not passed review.
 *
 * A 422 with no `code` is ordinary validation (an ineligible transaction in
 * the selection, nothing to settle); a 409 with no `code` is a state
 * conflict — the batch moved on, so reload it.
 */
export const SettlementErrorCodeSchema = z.enum([
  'slip_too_large',
  'slip_unsupported_type',
  'duplicate_bank_ref',
  'manager_required',
  'store_not_approved',
]);
export type SettlementErrorCode = z.infer<typeof SettlementErrorCodeSchema>;

function receiptForm(receipt: SettlementReceiptInput): FormData {
  const form = new FormData();
  form.append('amount', String(receipt.amount));
  form.append('bank_ref', receipt.bank_ref);
  form.append('slip', receipt.slip);
  return form;
}

/**
 * POST /api/merchant/settlements — multipart. THE receipt-first submission
 * (PLAN §1): selection + amount transferred + bank reference + slip, in one
 * multipart request that creates the settlement directly in
 * `payment_review`. There is no draft and no awaiting_payment on this path —
 * a settlement without a receipt cannot be created at all — and the lines
 * freeze on creation, so a rejected batch (not a re-edit) is how a mistake
 * is undone. Manager or above, approved store only. 201 with lines and
 * payments loaded.
 */
export function createMerchantSettlement(
  selection: SettlementSelection,
  receipt: SettlementReceiptInput,
  options: RequestOptions = {},
): Promise<MerchantSettlementResponse> {
  const form = receiptForm(receipt);
  appendSelection(form, selection);

  return apiFetch(
    '/api/merchant/settlements',
    MerchantSettlementResponseSchema,
    { method: 'POST', body: form, signal: options.signal },
  );
}

/**
 * POST /api/merchant/settlements/{id}/receipts — multipart. A FURTHER
 * transfer against a batch that is still owed money: the remainder after a
 * partial payment (§7 leaves the uncovered lines frozen on this batch, so no
 * new settlement can pick them up), or the transfer for a batch an admin
 * built as the fallback. 201 with the reloaded settlement.
 */
export function addMerchantSettlementReceipt(
  id: number,
  receipt: SettlementReceiptInput,
  options: RequestOptions = {},
): Promise<MerchantSettlementResponse> {
  return apiFetch(
    `/api/merchant/settlements/${id}/receipts`,
    MerchantSettlementResponseSchema,
    { method: 'POST', body: receiptForm(receipt), signal: options.signal },
  );
}

/**
 * POST /api/merchant/settlements/wallet — build and settle from the wallet
 * balance in one call (§7: same path, same states, same ledger entries; only
 * the funding source differs). No receipt exists because no bank transfer
 * happened — the top-up that funded the wallet is the evidence. A batch fully
 * netted to zero by §7 credits also settles here, drawing nothing. 422 when
 * the balance cannot cover the batch.
 */
export function createMerchantWalletSettlement(
  selection: SettlementSelection,
  options: RequestOptions = {},
): Promise<MerchantSettlementResponse> {
  return apiFetch(
    '/api/merchant/settlements/wallet',
    MerchantSettlementResponseSchema,
    { method: 'POST', body: selection, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// GET /api/merchant/wallet
// ---------------------------------------------------------------------------

export const MerchantWalletResponseSchema = dataWrapped(WalletSchema);
export type MerchantWalletResponse = z.infer<
  typeof MerchantWalletResponseSchema
>;

export function getMerchantWallet(
  options: RequestOptions = {},
): Promise<MerchantWalletResponse> {
  return apiFetch('/api/merchant/wallet', MerchantWalletResponseSchema, {
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// POST /api/merchant/credits
// ---------------------------------------------------------------------------

/**
 * One line of an optional line-item split (Task #25). `category` is one of
 * the merchant's product-category slugs, or null for the explicit default
 * "everything else" bucket (standing rate). Each category — and the default
 * bucket — may appear at most once (422 `duplicate_category_line`); an
 * unknown or another merchant's slug answers 422 `unknown_category`, a
 * deactivated one 422 `inactive_category`.
 */
export const CreditLineInputSchema = z.object({
  category: z.string().max(80).nullable(),
  /** Integer laari, >= 1. Line amounts MUST sum to eligible_amount. */
  amount_laari: z.number().int().min(1),
});
export type CreditLineInput = z.infer<typeof CreditLineInputSchema>;

export const CreateCreditRequestSchema = z.object({
  /** The customer's 6-digit code. */
  customer_code: z.string().regex(/^\d{6}$/),
  invoice_no: z.string().min(1).max(64),
  /** Integer laari; cashback and fee are computed server-side (ceiling). */
  eligible_amount: z.number().int().min(0),
  /** Integer laari; must be >= eligible_amount when given. */
  sale_amount: z.number().int().optional(),
  /**
   * OPTIONAL (PLAN §1, decision 2026-08-15) — omit it and the sale is
   * recorded as happening NOW, which is what a till ringing it up means.
   * Two accepted shapes: ISO 8601 WITH an offset
   * ("2026-08-15T13:45:00+05:00", "…Z", "…+0500"), or a plain wall clock
   * with NO offset ("2026-08-15 13:45:00" / "2026-08-15T13:45:00"), which
   * is read as MALDIVES time (Indian/Maldives) rather than refused. Any
   * other shape is a 422 field error; future-dated is still refused, and
   * the backdated rule is unchanged.
   */
  occurred_at: z.string().optional(),
  /**
   * PER-SALE RATE OVERRIDE (PLAN §1, decision 2026-08-15) — a 2-decimal
   * percent applying to THIS sale only ("2.5", "3", or the JSON number
   * 2.5). It may only ever RAISE what the sale would otherwise earn: below
   * the standing rate, or below a live promotion covering the sale, the
   * credit is refused 422 `rate_below_advertised` (the advertised rate is a
   * public promise). Above the active fee tier schedule's ceiling it is
   * refused 422 `rate_not_priced`. The applied rate is frozen on the row as
   * always, and the fee tier follows it.
   *
   * With `lines`, the override becomes the rate for every line that would
   * otherwise price at the standing rate; category overrides and exclusions
   * are untouched, and the per-line promotion floor still holds — no line
   * ever earns less than it would have without the override.
   */
  cashback_rate_percent: CashbackPercentInputSchema.optional(),
  /**
   * Optional line-item split. When present, SUM(amount_laari) must equal
   * eligible_amount (422 `lines_sum_mismatch`) and each line prices at its
   * own effective rate per §4 (per-line ceiling, totals = sum of stored
   * lines); the response transaction then carries the priced `lines`.
   * When absent the whole amount earns the single standing/promo rate,
   * byte-identical to the pre-lines behaviour.
   */
  lines: z.array(CreditLineInputSchema).min(1).max(100).optional(),
});
export type CreateCreditRequest = z.infer<typeof CreateCreditRequestSchema>;

export const CreateCreditResponseSchema = dataWrapped(TransactionSchema);
export type CreateCreditResponse = z.infer<typeof CreateCreditResponseSchema>;

/**
 * The 422 `code`s a manual credit can be refused with, all readable via
 * `apiErrorCode`:
 *
 *  - `rate_below_advertised` — the `cashback_rate_percent` override is below
 *    the rate the sale already earns (standing, or a live promotion). The
 *    body also carries `advertised_cashback_rate_percent`, the floor it had
 *    to clear — read it with `advertisedRatePercent(error)`.
 *  - `rate_not_priced` — the override is above the active fee tier
 *    schedule's ceiling, so the platform prices no fee for it.
 *  - `lines_sum_mismatch` / `unknown_category` / `inactive_category` /
 *    `duplicate_category_line` — the line-split rules (Task #25).
 */
export const CREDIT_REFUSAL_CODES = [
  'rate_below_advertised',
  'rate_not_priced',
  'lines_sum_mismatch',
  'unknown_category',
  'inactive_category',
  'duplicate_category_line',
] as const;
export const CreditRefusalCodeSchema = z.enum(CREDIT_REFUSAL_CODES);
export type CreditRefusalCode = (typeof CREDIT_REFUSAL_CODES)[number];

/**
 * The rate a `rate_below_advertised` refusal says the sale already earns, as
 * the 2-decimal percent string the API sent ("2.00") — so the form can say
 * "this sale already earns 2.00%" instead of a bare error. Null for any
 * other failure.
 */
export function advertisedRatePercent(error: unknown): string | null {
  if (!(error instanceof ApiError) || typeof error.body !== 'object') {
    return null;
  }
  const advertised = (
    error.body as { advertised_cashback_rate_percent?: unknown } | null
  )?.advertised_cashback_rate_percent;
  return typeof advertised === 'string' ? advertised : null;
}

/**
 * POST /api/merchant/credits — records a manual credit (201).
 *
 * `occurred_at` is OPTIONAL (PLAN §1): omit it for a sale happening now.
 * `cashback_rate_percent` is the optional per-sale override, which may only
 * raise the advertised rate (see CREDIT_REFUSAL_CODES).
 *
 * BACKDATED (PLAN §1): when `occurred_at` is older than the merchant's
 * validation window plus the grace days, the credit skips on_hold entirely —
 * it is payable immediately and the merchant can NEVER reverse it (admin
 * adjustment only). The response carries `backdated: true`. Warn before
 * submit with `isBackdatedOccurrence(occurred_at, validation_window_days)`,
 * which mirrors the server rule; afterwards there is nothing to undo. A
 * credit posted without `occurred_at` happens now and is never backdated.
 */
export function createMerchantCredit(
  body: CreateCreditRequest,
  options: RequestOptions = {},
): Promise<CreateCreditResponse> {
  return apiFetch('/api/merchant/credits', CreateCreditResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Product categories (Task #25)
// ---------------------------------------------------------------------------

/**
 * One per-store product category. The `slug` is generated from `name_en` at
 * creation and is IMMUTABLE — it is the `lines[].category` key vendors and
 * the credit form submit, and transaction lines snapshot it; renames never
 * touch it. Mode/rate changes reprice FUTURE credits only.
 * `cashback_rate_percent` is set exactly when `mode` is `'rate'`, null when
 * `'excluded'`.
 */
export const ProductCategorySchema = z.object({
  id: z.number().int(),
  slug: z.string(),
  name_en: z.string(),
  name_dv: z.string().nullable(),
  mode: ProductCategoryModeSchema,
  /** 2-decimal percent string; null exactly when mode is "excluded". */
  cashback_rate_percent: PercentSchema.nullable(),
  active: z.boolean(),
  sort: z.number().int(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});
export type ProductCategory = z.infer<typeof ProductCategorySchema>;

export const ProductCategoryListResponseSchema = z.object({
  data: z.array(ProductCategorySchema),
});
export type ProductCategoryListResponse = z.infer<
  typeof ProductCategoryListResponseSchema
>;

export const ProductCategoryResponseSchema = dataWrapped(ProductCategorySchema);
export type ProductCategoryResponse = z.infer<
  typeof ProductCategoryResponseSchema
>;

const productCategoryRequestBase = z.object({
  name_en: z.string().min(1).max(120),
  name_dv: z.string().min(1).max(120),
  sort: z.number().int().min(0).max(100000).optional(),
});

/**
 * The mode/rate pair is coherent by construction: an exclusion never
 * carries a rate, a rate override always does. Rates follow the standing-
 * rate sellability law — 0.50%–20.00% structurally, and rates the active
 * fee tier schedule does not price are refused server-side with a 422
 * `code: rate_not_priced`. The rate travels as a 2-decimal percent ("2.5"
 * or the JSON number 2.5), never as basis points.
 */
export const CreateProductCategoryRequestSchema = z.discriminatedUnion('mode', [
  productCategoryRequestBase.extend({ mode: z.literal('excluded') }),
  productCategoryRequestBase.extend({
    mode: z.literal('rate'),
    cashback_rate_percent: CashbackPercentInputSchema,
  }),
]);
export type CreateProductCategoryRequest = z.infer<
  typeof CreateProductCategoryRequestSchema
>;

/**
 * Partial update; omitted keys are untouched. The server validates the
 * FINAL mode/rate pair (post-merge): mode `rate` must end up with a rate,
 * mode `excluded` must end up without one — so switching to `excluded`
 * means sending `{mode: 'excluded', cashback_rate_percent: null}`. The slug
 * never changes.
 */
export const UpdateProductCategoryRequestSchema = z.object({
  name_en: z.string().min(1).max(120).optional(),
  /** Omit to leave alone; it may not be blanked once set (server 422). */
  name_dv: z.string().min(1).max(120).optional(),
  mode: ProductCategoryModeSchema.optional(),
  cashback_rate_percent: CashbackPercentInputSchema.nullable().optional(),
  sort: z.number().int().min(0).max(100000).optional(),
  active: z.boolean().optional(),
});
export type UpdateProductCategoryRequest = z.infer<
  typeof UpdateProductCategoryRequestSchema
>;

/**
 * GET /api/merchant/product-categories — STAFF-readable (it feeds the
 * credit form), sort then id order, inactive rows included (the settings
 * screen manages them; filter on `active` for the credit form).
 */
export function listMerchantProductCategories(
  options: RequestOptions = {},
): Promise<ProductCategoryListResponse> {
  return apiFetch(
    '/api/merchant/product-categories',
    ProductCategoryListResponseSchema,
    { signal: options.signal },
  );
}

/** POST /api/merchant/product-categories — owner only, approved stores only (201). */
export function createMerchantProductCategory(
  body: CreateProductCategoryRequest,
  options: RequestOptions = {},
): Promise<ProductCategoryResponse> {
  return apiFetch(
    '/api/merchant/product-categories',
    ProductCategoryResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

/**
 * PATCH /api/merchant/product-categories/{id} — owner only. There is
 * deliberately no DELETE: deactivation (`active: false`) is the only
 * removal, because historical transaction lines reference the category.
 */
export function updateMerchantProductCategory(
  id: number,
  body: UpdateProductCategoryRequest,
  options: RequestOptions = {},
): Promise<ProductCategoryResponse> {
  return apiFetch(
    `/api/merchant/product-categories/${id}`,
    ProductCategoryResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Promotions (Phase 3)
// ---------------------------------------------------------------------------

/**
 * The §4 all-in cost picture returned alongside create and publish: what the
 * merchant pays per transaction during the promotion versus their standing
 * terms at the window start. `tier_changed` is the tier-cliff warning the UI
 * must surface (e.g. 4.99% → 5.00%: +0.01pp cashback costs +0.26pp all-in).
 */
export const PromotionCostPreviewSchema = z.object({
  promo: RateDescriptionSchema,
  /** Null when no standing rate is effective at the window start. */
  standing: RateDescriptionSchema.nullable(),
  /**
   * The all-in difference as a SIGNED 2-decimal percent string ("0.26",
   * "-0.26"); null when there is no standing rate to compare against.
   */
  all_in_delta_percent: PercentDeltaSchema.nullable(),
  tier_changed: z.boolean(),
});
export type PromotionCostPreview = z.infer<typeof PromotionCostPreviewSchema>;

export const MerchantPromotionListResponseSchema = z.object({
  data: z.array(PromotionSchema),
});
export type MerchantPromotionListResponse = z.infer<
  typeof MerchantPromotionListResponseSchema
>;

export const MerchantPromotionResponseSchema = dataWrapped(PromotionSchema);
export type MerchantPromotionResponse = z.infer<
  typeof MerchantPromotionResponseSchema
>;

export const MerchantPromotionWithPreviewResponseSchema = z.object({
  data: PromotionSchema,
  cost_preview: PromotionCostPreviewSchema,
});
export type MerchantPromotionWithPreviewResponse = z.infer<
  typeof MerchantPromotionWithPreviewResponseSchema
>;

/** GET /api/merchant/promotions — newest first; staff may read, owner mutates. */
export function listMerchantPromotions(
  params: { status?: PromotionStatus } = {},
  options: RequestOptions = {},
): Promise<MerchantPromotionListResponse> {
  const query =
    params.status !== undefined
      ? `?status=${encodeURIComponent(params.status)}`
      : '';
  return apiFetch(
    `/api/merchant/promotions${query}`,
    MerchantPromotionListResponseSchema,
    { signal: options.signal },
  );
}

export const CreatePromotionRequestSchema = z.object({
  /**
   * A 2-decimal percent, 0.50%–20.00% (§4 structural cap), which must
   * exceed the standing rate. Rates the ACTIVE fee tier schedule does not
   * price are refused server-side with a 422 `code: rate_not_priced` —
   * structurally legal but unsellable until the admin publishes a wider
   * schedule.
   */
  cashback_rate_percent: CashbackPercentInputSchema,
  /**
   * ISO 8601 with an explicit UTC offset, e.g. "2026-09-01T00:00:00+05:00".
   * A promotion WINDOW is a scheduling instant, not a sale instant, so both
   * ends still require the offset — unlike a credit's `occurred_at`.
   */
  starts_at: z.string(),
  ends_at: z.string(),
  /** Integer laari. */
  min_purchase_laari: z.number().int().min(0).optional(),
  /** Integer laari; the per-customer promo cap. */
  max_cashback_per_customer_laari: z.number().int().min(1).optional(),
  /** Omit for a merchant-wide promotion. */
  branch_id: z.number().int().optional(),
});
export type CreatePromotionRequest = z.infer<
  typeof CreatePromotionRequestSchema
>;

/** POST /api/merchant/promotions — creates a draft (201). Owner only (403). */
export function createMerchantPromotion(
  body: CreatePromotionRequest,
  options: RequestOptions = {},
): Promise<MerchantPromotionWithPreviewResponse> {
  return apiFetch(
    '/api/merchant/promotions',
    MerchantPromotionWithPreviewResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

/**
 * POST /api/merchant/promotions/{id}/publish — draft → published. Owner
 * only. Once published the promotion is IMMUTABLE for its stated duration —
 * there is deliberately no update and no early-end endpoint; a non-draft
 * answers 409.
 */
export function publishMerchantPromotion(
  id: number,
  options: RequestOptions = {},
): Promise<MerchantPromotionWithPreviewResponse> {
  return apiFetch(
    `/api/merchant/promotions/${id}/publish`,
    MerchantPromotionWithPreviewResponseSchema,
    { method: 'POST', signal: options.signal },
  );
}

/**
 * POST /api/merchant/promotions/{id}/cancel — withdraws a DRAFT. Owner only.
 * A published promotion can never be cancelled (409) — that would be the
 * forbidden early end.
 */
export function cancelMerchantPromotion(
  id: number,
  options: RequestOptions = {},
): Promise<MerchantPromotionResponse> {
  return apiFetch(
    `/api/merchant/promotions/${id}/cancel`,
    MerchantPromotionResponseSchema,
    { method: 'POST', signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Settings — profile (owner only)
// ---------------------------------------------------------------------------

/**
 * The owner-editable merchant profile. `name`, `slug` and `status` are
 * read-only display — renaming the business is an identity change and stays
 * admin-only (a PATCHed `name` is dropped server-side). `eligibility_basis`
 * is the §11 free-text mirror of the agreement, displayed to customers,
 * never used in computation. `category` is a SLUG from the superadmin-
 * curated store-category list (no free text); `channel` replaces the former
 * `is_online` boolean.
 */
/**
 * The two lifecycle refusals the merchant panel can meet on a write, both
 * `409` with `code` (read them with `apiErrorCode`):
 *
 *  - `store_not_approved` — the store has not passed review. Route the
 *    owner back to the setup wizard.
 *  - `store_not_trading` — the store is approved but SUSPENDED or CLOSED,
 *    so it is not creating cashback (PLAN §7) and its commercial offer is
 *    frozen: the standing rate, promotions and the product-category rate
 *    card all refuse. Everything needed to END a suspension — settling,
 *    receipts, profile, branches, staff, every read — stays open, so never
 *    treat this as "the panel is locked".
 */
export const MerchantWriteGateCodeSchema = z.enum([
  'store_not_approved',
  'store_not_trading',
]);
export type MerchantWriteGateCode = z.infer<typeof MerchantWriteGateCodeSchema>;

export const MerchantProfileSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** The store's own name in Thaana; null when it has not supplied one. */
  name_dv: z.string().nullable().catch(null),
  slug: z.string(),
  status: MerchantStatusSchema,
  category: z.string().nullable(),
  /**
   * True when the store still holds a curated category the superadmin has
   * since deactivated. Saving is NOT blocked by it — re-sending the
   * unchanged value is accepted — but the picker cannot offer it any more,
   * so the screen must say why and ask for a new pick.
   */
  category_retired: z.boolean(),
  /** Never rendered as the literal "both" — display "In Store & Online". */
  channel: MerchantChannelSchema,
  eligibility_basis: z.string().nullable(),
  contact_email: z.string().nullable(),
  contact_phone: z.string().nullable(),
});
export type MerchantProfile = z.infer<typeof MerchantProfileSchema>;

export const MerchantProfileResponseSchema = dataWrapped(MerchantProfileSchema);
export type MerchantProfileResponse = z.infer<
  typeof MerchantProfileResponseSchema
>;

export const UpdateMerchantProfileRequestSchema = z.object({
  /**
   * The Thaana name. Editable by the owner even though `name` is not: this
   * is a translation of the display name rather than the store's identity,
   * and nothing (the slug included) is derived from it.
   */
  name_dv: z.string().max(120).nullable().optional(),
  /**
   * An ACTIVE curated store-category slug (422 otherwise), or null — with
   * one exception: the value the store already holds is always accepted,
   * even after the superadmin retires it, so a retired category can never
   * block an edit to an unrelated field. Omit the key to leave it alone.
   */
  category: z.string().max(80).nullable().optional(),
  channel: MerchantChannelSchema.optional(),
  eligibility_basis: z.string().max(2000).nullable().optional(),
  contact_email: z.email().max(255).nullable().optional(),
  contact_phone: z.string().max(32).nullable().optional(),
});
export type UpdateMerchantProfileRequest = z.infer<
  typeof UpdateMerchantProfileRequestSchema
>;

/** GET /api/merchant/profile — owner only. */
export function getMerchantProfile(
  options: RequestOptions = {},
): Promise<MerchantProfileResponse> {
  return apiFetch('/api/merchant/profile', MerchantProfileResponseSchema, {
    signal: options.signal,
  });
}

/** PATCH /api/merchant/profile — partial update; omitted keys are untouched. */
export function updateMerchantProfile(
  body: UpdateMerchantProfileRequest,
  options: RequestOptions = {},
): Promise<MerchantProfileResponse> {
  return apiFetch('/api/merchant/profile', MerchantProfileResponseSchema, {
    method: 'PATCH',
    body,
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Settings — bank account (owner only)
// ---------------------------------------------------------------------------

/**
 * The merchant's own bank identity, used for matching INBOUND settlement
 * payments (and future wallet withdrawals) — never a payout destination:
 * money flows merchant → platform.
 */
export const MerchantBankAccountSchema = z.object({
  bank_name: z.string(),
  bank_account: z.string(),
  bank_account_name: z.string(),
});
export type MerchantBankAccount = z.infer<typeof MerchantBankAccountSchema>;

export const MerchantBankAccountResponseSchema = dataWrapped(
  MerchantBankAccountSchema,
);
export type MerchantBankAccountResponse = z.infer<
  typeof MerchantBankAccountResponseSchema
>;

/**
 * The bank identity is one atomic triple — a half-updated identity
 * mismatches every payment — so all three fields are required together.
 */
export const UpdateMerchantBankAccountRequestSchema = z.object({
  bank_name: z.string().min(1).max(255),
  bank_account: z.string().min(1).max(64),
  bank_account_name: z.string().min(1).max(255),
});
export type UpdateMerchantBankAccountRequest = z.infer<
  typeof UpdateMerchantBankAccountRequestSchema
>;

/** PATCH /api/merchant/bank-account — owner only; all three fields together. */
export function updateMerchantBankAccount(
  body: UpdateMerchantBankAccountRequest,
  options: RequestOptions = {},
): Promise<MerchantBankAccountResponse> {
  return apiFetch(
    '/api/merchant/bank-account',
    MerchantBankAccountResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Settings — branches (owner only)
// ---------------------------------------------------------------------------

/** Coordinates are floats deliberately — geography, not money. */
export const MerchantBranchSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  address: z.string().nullable(),
  lat: z.number().nullable(),
  lng: z.number().nullable(),
});
export type MerchantBranch = z.infer<typeof MerchantBranchSchema>;

export const MerchantBranchListResponseSchema = z.object({
  data: z.array(MerchantBranchSchema),
});
export type MerchantBranchListResponse = z.infer<
  typeof MerchantBranchListResponseSchema
>;

export const MerchantBranchResponseSchema = dataWrapped(MerchantBranchSchema);
export type MerchantBranchResponse = z.infer<
  typeof MerchantBranchResponseSchema
>;

/**
 * lat/lng are a nullable PAIR: both set or both null, never one — the
 * server 422s a lone coordinate (on update, after merging with the stored
 * branch).
 */
export const CreateMerchantBranchRequestSchema = z.object({
  name: z.string().min(1).max(255),
  address: z.string().max(1000).nullable().optional(),
  lat: z.number().min(-90).max(90).nullable().optional(),
  lng: z.number().min(-180).max(180).nullable().optional(),
});
export type CreateMerchantBranchRequest = z.infer<
  typeof CreateMerchantBranchRequestSchema
>;

export const UpdateMerchantBranchRequestSchema =
  CreateMerchantBranchRequestSchema.partial();
export type UpdateMerchantBranchRequest = z.infer<
  typeof UpdateMerchantBranchRequestSchema
>;

/** GET /api/merchant/branches — all branches, id order. */
export function listMerchantBranches(
  options: RequestOptions = {},
): Promise<MerchantBranchListResponse> {
  return apiFetch('/api/merchant/branches', MerchantBranchListResponseSchema, {
    signal: options.signal,
  });
}

/** POST /api/merchant/branches — creates a branch (201). */
export function createMerchantBranch(
  body: CreateMerchantBranchRequest,
  options: RequestOptions = {},
): Promise<MerchantBranchResponse> {
  return apiFetch('/api/merchant/branches', MerchantBranchResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

/** PATCH /api/merchant/branches/{id} — partial update. */
export function updateMerchantBranch(
  id: number,
  body: UpdateMerchantBranchRequest,
  options: RequestOptions = {},
): Promise<MerchantBranchResponse> {
  return apiFetch(
    `/api/merchant/branches/${id}`,
    MerchantBranchResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}

/**
 * DELETE /api/merchant/branches/{id} — 204 on success. A branch referenced
 * by transactions or branch-scoped promotions is history that must keep
 * resolving: the server answers 409 with code `branch_referenced` (thrown
 * here as ApiError) and the soft alternative is simply to stop using it.
 */
export async function deleteMerchantBranch(
  id: number,
  options: RequestOptions = {},
): Promise<void> {
  await apiFetch(`/api/merchant/branches/${id}`, z.undefined(), {
    method: 'DELETE',
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Settings — staff (owner only)
// ---------------------------------------------------------------------------

/**
 * The three merchant panel tiers (PLAN §1, decision 2026-08-15), listed
 * DESCENDING in authority:
 *
 *  - `owner`   everything, including the bank account, staff management,
 *              preferences, the store profile, the logo and the API
 *              credential listing;
 *  - `manager` the operating surface — cashback rate, promotions,
 *              settlements, branches and product categories — and nothing
 *              that moves money out or mints accounts;
 *  - `staff`   credit entry, the customer lookup and the read screens.
 *
 * Mirrors the merchant_users_role_check constraint; the API answers 403
 * `owner_required` / `manager_required` naming the tier a route needs.
 */
export const MerchantStaffRoleSchema = z.enum(['owner', 'manager', 'staff']);
export type MerchantStaffRole = z.infer<typeof MerchantStaffRoleSchema>;

export const MerchantStaffSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
  role: MerchantStaffRoleSchema,
  is_active: z.boolean(),
  created_at: z.string().nullable(),
});
export type MerchantStaff = z.infer<typeof MerchantStaffSchema>;

export const MerchantStaffListResponseSchema = z.object({
  data: z.array(MerchantStaffSchema),
});
export type MerchantStaffListResponse = z.infer<
  typeof MerchantStaffListResponseSchema
>;

export const MerchantStaffResponseSchema = dataWrapped(MerchantStaffSchema);
export type MerchantStaffResponse = z.infer<typeof MerchantStaffResponseSchema>;

export const CreateMerchantStaffRequestSchema = z.object({
  name: z.string().min(1).max(255),
  /** Must be unique across all merchant panel accounts (422 otherwise). */
  email: z.email().max(255),
  /**
   * The tier the invite lands in. Omitted means `staff` — the invite is
   * back-compatible with the two-tier API. Only an owner may send this
   * (the whole staff surface is owner-gated).
   */
  role: MerchantStaffRoleSchema.optional(),
});
export type CreateMerchantStaffRequest = z.infer<
  typeof CreateMerchantStaffRequestSchema
>;

export const CreateMerchantStaffResponseSchema = z.object({
  data: MerchantStaffSchema,
  /**
   * The generated temporary password — returned exactly ONCE, on creation,
   * and never retrievable again (only the hash survives). The panel must
   * display it immediately for handover.
   */
  temp_password: z.string(),
});
export type CreateMerchantStaffResponse = z.infer<
  typeof CreateMerchantStaffResponseSchema
>;

export const UpdateMerchantStaffRequestSchema = z.object({
  role: MerchantStaffRoleSchema.optional(),
  is_active: z.boolean().optional(),
});
export type UpdateMerchantStaffRequest = z.infer<
  typeof UpdateMerchantStaffRequestSchema
>;

/** GET /api/merchant/staff — every panel account, id order. */
export function listMerchantStaff(
  options: RequestOptions = {},
): Promise<MerchantStaffListResponse> {
  return apiFetch('/api/merchant/staff', MerchantStaffListResponseSchema, {
    signal: options.signal,
  });
}

/** POST /api/merchant/staff — creates a staff account (201) + one-time temp password. */
export function createMerchantStaff(
  body: CreateMerchantStaffRequest,
  options: RequestOptions = {},
): Promise<CreateMerchantStaffResponse> {
  return apiFetch('/api/merchant/staff', CreateMerchantStaffResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

/**
 * PATCH /api/merchant/staff/{id} — role and/or activation. There is
 * deliberately no DELETE: deactivation is the only removal. Demoting or
 * deactivating the last active owner answers 422.
 */
export function updateMerchantStaff(
  id: number,
  body: UpdateMerchantStaffRequest,
  options: RequestOptions = {},
): Promise<MerchantStaffResponse> {
  return apiFetch(`/api/merchant/staff/${id}`, MerchantStaffResponseSchema, {
    method: 'PATCH',
    body,
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Settings — preferences (owner only)
// ---------------------------------------------------------------------------

/**
 * Operational preferences: how settlements are funded (§7 — wallet is a
 * funding method, not pre-funding) and the two per-merchant earning knobs.
 * Both knobs apply to FUTURE credits only — terms freeze onto each
 * transaction at occurred_at (§4), so history never moves.
 */
export const MerchantPreferencesSchema = z.object({
  settlement_method: SettlementFundingMethodSchema,
  /** Integer laari. */
  min_eligible_laari: z.number().int(),
  validation_window_days: z.number().int(),
  /**
   * Admin-governed ceiling for validation_window_days (the platform's
   * default_validation_window_days setting). The §11 stale-review window
   * is not merchant-raisable — render THIS as the form bound.
   */
  validation_window_max_days: z.number().int(),
});
export type MerchantPreferences = z.infer<typeof MerchantPreferencesSchema>;

export const MerchantPreferencesResponseSchema = dataWrapped(
  MerchantPreferencesSchema,
);
export type MerchantPreferencesResponse = z.infer<
  typeof MerchantPreferencesResponseSchema
>;

export const UpdateMerchantPreferencesRequestSchema = z.object({
  settlement_method: SettlementFundingMethodSchema.optional(),
  /** Integer laari; platform bounds 0–100000 (MVR 0–1,000). */
  min_eligible_laari: z.number().int().min(0).max(100000).optional(),
  /**
   * Days. The server enforces the platform ceiling (the response's
   * validation_window_max_days); 30 is only the absolute platform range.
   */
  validation_window_days: z.number().int().min(0).max(30).optional(),
});
export type UpdateMerchantPreferencesRequest = z.infer<
  typeof UpdateMerchantPreferencesRequestSchema
>;

/** PATCH /api/merchant/preferences — partial update; owner only. */
export function updateMerchantPreferences(
  body: UpdateMerchantPreferencesRequest,
  options: RequestOptions = {},
): Promise<MerchantPreferencesResponse> {
  return apiFetch(
    '/api/merchant/preferences',
    MerchantPreferencesResponseSchema,
    { method: 'PATCH', body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// GET /api/merchant/customers/lookup — staff-accessible
// ---------------------------------------------------------------------------

/**
 * The credit screen's cashier confirmation (§11 phone-recycling control):
 * resolves a 6-digit customer code to the customer's NAME, so the right
 * person is credited before a manual credit is posted. Returned in full:
 * confirming a masked fragment against the person at the counter is not a
 * confirmation, and crediting a stranger is the failure it exists to stop.
 *
 * An unknown code and a known-but-blocked customer answer identically —
 * a plain 200 `{valid: false}` — so the endpoint is no existence oracle.
 * Throttled 30/min per user, matching the credit POST.
 */
export const CustomerLookupResponseSchema = z.discriminatedUnion('valid', [
  z.object({ valid: z.literal(true), name: z.string() }),
  z.object({ valid: z.literal(false) }),
]);
export type CustomerLookupResponse = z.infer<
  typeof CustomerLookupResponseSchema
>;

export function lookupMerchantCustomer(
  code: string,
  options: RequestOptions = {},
): Promise<CustomerLookupResponse> {
  return apiFetch(
    `/api/merchant/customers/lookup?code=${encodeURIComponent(code)}`,
    CustomerLookupResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Settings — API access (owner only)
// ---------------------------------------------------------------------------

/**
 * The closed set of abilities a vendor token can carry (PLAN §9.1). Every
 * /v1 operation requires exactly one; a valid token lacking it answers 403.
 * Mirrors App\Domain\Credentials\VendorAbility — the server rejects anything
 * outside this list with a 422 on `abilities.*`.
 */
export const VENDOR_ABILITIES = [
  'transactions:write',
  'transactions:reverse',
  'rates:read',
  'customers:lookup',
] as const;
export const VendorAbilitySchema = z.enum(VENDOR_ABILITIES);
export type VendorAbility = (typeof VENDOR_ABILITIES)[number];

/**
 * Narrows an ability string off the wire. Stored rows carry plain strings,
 * so an older credential may name an ability this build predates — label
 * helpers test with this and fall back to prose, never to the raw code.
 */
export function isVendorAbility(value: string): value is VendorAbility {
  return (VENDOR_ABILITIES as readonly string[]).includes(value);
}

/**
 * One issued credential, as both panels see it. There is deliberately no
 * token material here — not the plaintext, not its digest, not the Sanctum
 * token id. The plaintext exists exactly once, in the create response.
 */
export const MerchantCredentialSchema = z.object({
  id: z.number().int(),
  merchant_id: z.number().int(),
  /** Set when Manfaa issued the credential against a curated POS vendor. */
  pos_vendor: z.object({ id: z.number().int(), name: z.string() }).nullable(),
  /** The partner name the merchant typed on the self-serve path. */
  label: z.string().nullable(),
  /** `pos_vendor.name ?? label`, resolved server-side so panels agree. */
  display_name: z.string(),
  abilities: z.array(z.string()),
  issued_by: z.number().int().nullable(),
  /**
   * WHO minted it: `merchant_user` (this store, self-serve — `name` is the
   * owner who did it) or `admin` (Manfaa at onboarding — no name is exposed
   * to the merchant panel).
   */
  issuer: z.object({
    type: z.enum(['merchant_user', 'admin', 'unknown']),
    name: z.string().nullable(),
  }),
  revoked_by_type: z.enum(['merchant_user', 'admin', 'unknown']).nullable(),
  /** Last time this token authenticated against /v1 — the "in use" signal. */
  last_used_at: z.string().nullable(),
  revoked_at: z.string().nullable(),
  revoked_by: z.number().int().nullable(),
  created_at: z.string().nullable(),
});
export type MerchantCredential = z.infer<typeof MerchantCredentialSchema>;

export const MerchantCredentialListResponseSchema = z.object({
  data: z.array(MerchantCredentialSchema),
});
export type MerchantCredentialListResponse = z.infer<
  typeof MerchantCredentialListResponseSchema
>;

export const MerchantCredentialResponseSchema = dataWrapped(
  MerchantCredentialSchema,
);
export type MerchantCredentialResponse = z.infer<
  typeof MerchantCredentialResponseSchema
>;

export const CreateMerchantCredentialRequestSchema = z.object({
  /** The integration partner, as the owner names it. 2–80 characters. */
  label: z.string().min(2).max(80),
  /** At least one, each from the closed set. */
  abilities: z.array(VendorAbilitySchema).min(1),
});
export type CreateMerchantCredentialRequest = z.infer<
  typeof CreateMerchantCredentialRequestSchema
>;

export const CreateMerchantCredentialResponseSchema = z.object({
  /**
   * The bearer token, returned EXACTLY once — only its SHA-256 digest is
   * stored, so it is never recoverable. Show it immediately, then let the
   * owner acknowledge that it is gone.
   */
  plaintext_token: z.string(),
  credential: MerchantCredentialSchema,
});
export type CreateMerchantCredentialResponse = z.infer<
  typeof CreateMerchantCredentialResponseSchema
>;

/** GET /api/merchant/credentials — newest first, revoked rows included. */
export function listMerchantCredentials(
  options: RequestOptions = {},
): Promise<MerchantCredentialListResponse> {
  return apiFetch(
    '/api/merchant/credentials',
    MerchantCredentialListResponseSchema,
    { signal: options.signal },
  );
}

/**
 * POST /api/merchant/credentials — mints a vendor token (201) plus the
 * one-time plaintext. Owner only. Refusals worth handling by code:
 *
 *  - `store_not_approved` (409) — the store has not passed review yet;
 *  - `store_not_trading` (409) — suspended or closed; revocation still works;
 *  - `credential_cap_reached` (422) — 10 live credentials already; revoke one;
 *  - `issuance_rate_limited` (429) — 5 per hour per store, with Retry-After.
 */
export function createMerchantCredential(
  body: CreateMerchantCredentialRequest,
  options: RequestOptions = {},
): Promise<CreateMerchantCredentialResponse> {
  return apiFetch(
    '/api/merchant/credentials',
    CreateMerchantCredentialResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

/**
 * DELETE /api/merchant/credentials/{id} — revokes ONE credential: the token
 * stops authenticating on its next request, siblings are untouched, and the
 * row survives as audit history. Another store's id answers 404.
 */
export function revokeMerchantCredential(
  id: number,
  options: RequestOptions = {},
): Promise<MerchantCredentialResponse> {
  return apiFetch(
    `/api/merchant/credentials/${id}`,
    MerchantCredentialResponseSchema,
    { method: 'DELETE', signal: options.signal },
  );
}
