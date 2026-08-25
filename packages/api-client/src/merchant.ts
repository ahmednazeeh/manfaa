import { z } from "zod";
import { ApiError, apiFetch } from "./client";
import {
  BankSlugSchema,
  CashbackPercentInputSchema,
  dataWrapped,
  MerchantChangeRequestSchema,
  MerchantChannelSchema,
  MerchantStatusSchema,
  paginated,
  QueuedChangeSchema,
  type MerchantChangeRequest,
  PercentDeltaSchema,
  PercentSchema,
  ProductCategoryModeSchema,
  PromotionSchema,
  PromotionStatusSchema,
  PromptDiscountReasonSchema,
  RateDescriptionSchema,
  SettlementBankAccountSchema,
  SettlementDestinationSchema,
  SettlementFundingMethodSchema,
  SettlementSchema,
  SettlementStateSchema,
  TransactionSchema,
  type PromotionStatus,
  WalletSchema,
  WalletTopUpSchema,
} from "./resources";

/**
 * Typed contracts for the merchant surface: outstanding by age bucket, the
 * settlement builder and lifecycle, the wallet, manual credits (Phase 1,
 * now with the optional lines[] split of Task #25), product-category CRUD,
 * the promotion builder (Phase 3), and the settings module (profile, bank
 * account, branches, staff, preferences, customer lookup). All amounts sent
 * and received are integer laari.
 *
 * Authority is a PERMISSION, never a tier (PLAN §13b): every merchant route
 * names exactly one slug from MERCHANT_PERMISSIONS and refuses with 403
 * `permission_required`, carrying the missing slug in `permission` so the
 * panel can say what the account would need. Each fetcher below records the
 * slug its route requires. Gate the UI on the resolved permission array
 * `/me` returns (MerchantAuthUserSchema) and never on the role — role names
 * are the store's own words now, and a set has no order to compare.
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
 * receipt cannot be created. Settlement MUTATIONS need `settlements.create`
 * plus an approved store; the preview and the reads carry their own narrower
 * permissions (`settlements.preview`, `settlements.view`).
 *
 * The wallet is PRE-FUNDABLE since 2026-08-24 (owner decision, reversing
 * the earlier "wallet is not pre-funding" rule): a merchant tops it up by
 * bank transfer through the SAME receipt-first act — pick the platform
 * account, transfer, upload the slip, optionally type the bank reference —
 * and the claim is auto-matched against bank history by the same rules, an
 * admin queue as fallback (`createMerchantWalletTopUp`, `wallet.top_up`).
 * When the wallet holds balance, validated cashback auto-settles from it
 * hourly, oldest first, as much as fits, behind the `auto_settle_from_wallet`
 * preference (default ON) — the same settlement path the wallet button uses.
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

/**
 * The all-buckets total, with the three legs spelled out as display strings.
 * `payable_mvr` is cashback + fee + GST — the tax is broken out BESIDE the
 * fee rather than folded into it (owner, 2026-08-24): the fee is Manfaa's
 * charge and the GST is a tax on that charge, and a merchant reading one
 * blended number cannot reconcile either. `fee_gst_mvr` is "0.00" while GST
 * is switched off; the per-bucket rows carry `fee_gst_laari` only.
 */
export const OutstandingTotalSchema = OutstandingBucketSchema.extend({
  cashback_mvr: z.string(),
  fee_mvr: z.string(),
  fee_gst_mvr: z.string(),
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
    "0_5": OutstandingBucketSchema,
    "6_10": OutstandingBucketSchema,
    "11_15": OutstandingBucketSchema,
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
  return apiFetch("/api/merchant/outstanding", OutstandingResponseSchema, {
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
    params.page !== undefined ? `?page=${encodeURIComponent(params.page)}` : "";
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
  if ("settle_all" in selection) {
    search.set("settle_all", "1");
  } else {
    for (const id of selection.transaction_ids) {
      search.append("transaction_ids[]", String(id));
    }
  }
  return `?${search.toString()}`;
}

/** The same selection, as multipart fields on a receipt submission. */
function appendSelection(form: FormData, selection: SettlementSelection): void {
  if ("settle_all" in selection) {
    form.append("settle_all", "1");
  } else {
    for (const id of selection.transaction_ids) {
      form.append("transaction_ids[]", String(id));
    }
  }
}

/**
 * What a selection would cost and where to send it — the preview's own
 * payment instructions. No reference is quoted here: the batch does not
 * exist until the receipt creates it, so any number offered now could be
 * taken by whoever submits first — after the merchant had already written
 * it on a bank transfer. The reference appears once, on the settlement.
 */
export const SettlementPreviewInstructionsSchema = z.object({
  // No reference before the settlement exists (owner decision 2026-08-18):
  // the batch is created by the receipt, and a number quoted earlier could
  // be taken by whoever submits first.
  amount_due_laari: z.number().int(),
  amount_due_mvr: z.string(),
  /**
   * The bill, ITEMISED, on the screen the merchant reads before walking to
   * the bank (owner, 2026-08-24): what the customers earned, what Manfaa
   * charged, and the tax on that charge. The three are the batch before
   * credits and the prompt-payment discount — they do NOT sum to
   * `amount_due_laari`, which is net of both; read the preview's
   * `line_total_laari` for the gross.
   */
  cashback_total_laari: z.number().int(),
  fee_total_laari: z.number().int(),
  fee_gst_total_laari: z.number().int(),
  bank_account: SettlementBankAccountSchema.nullable(),
  /** Every account the merchant may pick, one per bank. See the settled twin. */
  bank_accounts: z.array(SettlementDestinationSchema).catch([]),
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
  /**
   * The GST relief that rides along with the fee discount, recomputed
   * proportionally on the discounted fee — zero while GST is switched off,
   * and `discount_laari` is the two added up. Show the merchant the total;
   * this split exists so the accounting can credit 4100 and 2300 apart.
   */
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
  /**
   * Manfaa's own charge and the tax on it, as SEPARATE figures (owner,
   * 2026-08-24) — never one blended number. The merchant owes cashback +
   * fee + GST, which is `line_total_laari` before credits and the discount.
   * Both are sums of stored per-row integers, each row taxed at the rate
   * STAMPED on it, so a batch spanning two GST regimes still totals
   * exactly; that is also why no single rate is quoted here.
   */
  fee_total_laari: z.number().int(),
  fee_total_mvr: z.string(),
  fee_gst_total_laari: z.number().int(),
  fee_gst_total_mvr: z.string(),
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
  bank_ref?: string;
  slip: File | Blob;
  /**
   * WHICH platform account the transfer went to, from
   * `payment_instructions.bank_accounts`. Optional so a caller that only
   * ever showed one account still settles; when omitted the batch simply
   * does not record a destination, and reconciliation falls back to
   * whichever account is primary — which is exactly the ambiguity this
   * field exists to remove, so send it.
   */
  platform_bank_account_id?: number;
}

/** 5 MB — the server's own slip ceiling, so the panel can refuse first. */
export const SETTLEMENT_SLIP_MAX_BYTES = 5 * 1024 * 1024;

/** What the server accepts as a slip, by content — SVG is deliberately absent. */
export const SETTLEMENT_SLIP_ACCEPT =
  "image/jpeg,image/png,image/webp,application/pdf";

/**
 * Refusal codes carried on ApiError bodies as `code` for the receipt-first
 * routes (read them with `apiErrorCode`):
 *  - `slip_too_large` (422) — over 5 MB;
 *  - `slip_unsupported_type` (422) — the BYTES are not JPEG/PNG/WebP/PDF;
 *  - `duplicate_bank_ref` (409) — that reference is already recorded on this
 *    batch; the transfer is in the system and re-recording it would book the
 *    same cash twice;
 *  - `permission_required` (403) — the account may read settlements but not
 *    submit them. The body's `permission` names the slug that is missing
 *    (`settlements.create`, `settlements.receipt_add`), which is the whole
 *    reason the code is no longer a tier: there is no tier to name;
 *  - `store_not_approved` (409) — the store has not passed review.
 *
 * A 422 with no `code` is ordinary validation (an ineligible transaction in
 * the selection, nothing to settle); a 409 with no `code` is a state
 * conflict — the batch moved on, so reload it.
 */
export const SettlementErrorCodeSchema = z.enum([
  "slip_too_large",
  "slip_unsupported_type",
  "duplicate_bank_ref",
  "permission_required",
  "store_not_approved",
]);
export type SettlementErrorCode = z.infer<typeof SettlementErrorCodeSchema>;

function receiptForm(receipt: SettlementReceiptInput): FormData {
  const form = new FormData();
  form.append("amount", String(receipt.amount));
  if (receipt.bank_ref !== undefined && receipt.bank_ref !== "") {
    form.append("bank_ref", receipt.bank_ref);
  }
  form.append("slip", receipt.slip);
  if (receipt.platform_bank_account_id !== undefined) {
    form.append(
      "platform_bank_account_id",
      String(receipt.platform_bank_account_id),
    );
  }
  return form;
}

/**
 * POST /api/merchant/settlements — multipart. THE receipt-first submission
 * (PLAN §1): selection + amount transferred + bank reference + slip, in one
 * multipart request that creates the settlement directly in
 * `payment_review`. There is no draft and no awaiting_payment on this path —
 * a settlement without a receipt cannot be created at all — and the lines
 * freeze on creation, so a rejected batch (not a re-edit) is how a mistake
 * is undone. `settlements.create`, approved store only. 201 with lines and
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
    "/api/merchant/settlements",
    MerchantSettlementResponseSchema,
    { method: "POST", body: form, signal: options.signal },
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
    { method: "POST", body: receiptForm(receipt), signal: options.signal },
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
    "/api/merchant/settlements/wallet",
    MerchantSettlementResponseSchema,
    { method: "POST", body: selection, signal: options.signal },
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
  return apiFetch("/api/merchant/wallet", MerchantWalletResponseSchema, {
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// POST /api/merchant/wallet/top-ups — `wallet.top_up`
// ---------------------------------------------------------------------------

/**
 * A wallet top-up claim (owner, 2026-08-24): the same receipt half a
 * settlement submission carries, minus the selection — what was
 * transferred, WHICH platform account it went to, the slip, and the bank's
 * reference if the merchant has it. The slip is read by its BYTES like a
 * settlement slip (JPEG, PNG, WebP or PDF, max 5 MB — reuse
 * SETTLEMENT_SLIP_MAX_BYTES / SETTLEMENT_SLIP_ACCEPT; the disk and the
 * inspector are the same).
 */
export interface WalletTopUpInput {
  /**
   * Integer laari transferred, at least the wallet payload's
   * `top_up_min_laari` (MVR 100 by default) — below it the server answers a
   * plain 422 validation error on the `amount` field (`errors.amount`,
   * NO `code`): the floor is enforced by the request rule before the
   * domain, so read `errors.amount` and say the minimum yourself.
   */
  amount: number;
  /**
   * WHICH platform account the transfer went to — an `id` from the wallet
   * payload's `bank_accounts` (`getMerchantWallet().data.bank_accounts`;
   * the same active accounts a settlement's `payment_instructions` names).
   * REQUIRED here, unlike a settlement receipt: the verifier reads that
   * account's history to find the transfer, so a claim without a
   * destination could only ever be matched by hand.
   */
  platform_bank_account_id: number;
  slip: File | Blob;
  /**
   * The bank's reference for the transfer. Optional — the slip usually
   * carries it and OCR reads it — but when given it is the strongest
   * evidence, and it is unique per merchant across non-rejected claims.
   */
  bank_ref?: string;
}

/**
 * Refusal codes on ApiError bodies for the top-up claim (read them with
 * `apiErrorCode`):
 *  - `slip_too_large` (422) / `slip_unsupported_type` (422) — as for a
 *    settlement slip;
 *  - `duplicate_bank_ref` (409) — that reference is already claimed by a
 *    pending or matched top-up, already on one of this store's settlement
 *    receipts, or already booked into the wallet by an admin; re-claiming
 *    it would credit the same cash twice;
 *  - `too_many_pending_top_ups` (409) — the store already has 3 claims
 *    waiting on the bank or an admin; those must be decided first;
 *  - `permission_required` (403) — the body's `permission` names
 *    `wallet.top_up`;
 *  - `store_not_approved` (409) — the store has not passed review;
 *  - 429 — more than 5 claims in a minute.
 *
 * A 422 with no `code` is ordinary validation: an amount under
 * `top_up_min_laari` (`errors.amount`), an inactive or unknown
 * `platform_bank_account_id`, a missing slip.
 */
export const WalletTopUpErrorCodeSchema = z.enum([
  "slip_too_large",
  "slip_unsupported_type",
  "duplicate_bank_ref",
  "too_many_pending_top_ups",
  "permission_required",
  "store_not_approved",
]);
export type WalletTopUpErrorCode = z.infer<typeof WalletTopUpErrorCodeSchema>;

export const MerchantWalletTopUpResponseSchema = dataWrapped(WalletTopUpSchema);
export type MerchantWalletTopUpResponse = z.infer<
  typeof MerchantWalletTopUpResponseSchema
>;

function topUpForm(input: WalletTopUpInput): FormData {
  const form = new FormData();
  form.append("amount", String(input.amount));
  form.append(
    "platform_bank_account_id",
    String(input.platform_bank_account_id),
  );
  if (input.bank_ref !== undefined && input.bank_ref.trim() !== "") {
    form.append("bank_ref", input.bank_ref);
  }
  form.append("slip", input.slip);
  return form;
}

/**
 * POST /api/merchant/wallet/top-ups — multipart, mirroring the settlement
 * receipt submission. Creates a `pending` claim (201, with
 * `platform_bank_account` embedded) and starts watching the named account's
 * bank history; the wallet is credited only when the transfer is found
 * (auto) or an admin matches it, and the claim then reads `matched` with a
 * `wallet_transaction_id`. Until then it appears in the wallet payload's
 * `pending_top_ups`. `wallet.top_up`, approved store only. Also mounted for
 * the app at /api/mobile/v1/merchant/wallet/top-ups.
 */
export function createMerchantWalletTopUp(
  input: WalletTopUpInput,
  options: RequestOptions = {},
): Promise<MerchantWalletTopUpResponse> {
  return apiFetch(
    "/api/merchant/wallet/top-ups",
    MerchantWalletTopUpResponseSchema,
    { method: "POST", body: topUpForm(input), signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// GET /api/merchant/settlements/{id}/payment-progress — `settlements.view`
// GET /api/merchant/wallet/top-ups/{id}/progress      — `wallet.view`
// ---------------------------------------------------------------------------

/**
 * "What is happening to the transfer I just uploaded?" — one shape for both
 * flows (owner, 2026-08-25). A merchant sends money at the bank, uploads the
 * slip, and the server starts reading the destination account's own history
 * looking for it. These two reads are how a screen WATCHES that happen and
 * then shows the real outcome in the same place: `settled` for a settlement,
 * "MVR X added, balance now Y" for a top-up.
 *
 * THE HONESTY RULE. Never animate a progress bar over nothing. The server
 * only polls when the platform switch `auto_verify_enabled` is on, the
 * platform bank account the merchant paid into is routed to an ACTIVE verify
 * profile, and the row's poll window has not lapsed. Whether all three hold
 * is a SERVER fact, delivered as `watching` — the client must never infer it
 * from a timestamp, a state, or the mere existence of a claim. When
 * `watching` is false the screen stops the bar and says a person will
 * confirm the transfer shortly; `reason` says which of the four it is, so
 * the sentence can be true rather than vague.
 *
 * `reason` is null exactly when `watching` is true, never both.
 *
 * NOTHING HERE DRIVES THE SERVER. The poll runs whether or not a screen is
 * open; closing the app loses nothing, and the push + SMS on a match fire
 * regardless. This is a window, not a trigger — and it is read-only.
 *
 * POLLING. Every 5s is the intended cadence (the route allows 120/min, ten
 * times that). STOP when `watching` is false — a terminal row and a lapsed
 * or never-started watch both land there, and `outcome` is non-null on every
 * terminal one. See {@link isTransferWatched}.
 *
 * `attempts === 0` while `watching` is true is the ordinary first second:
 * the job is queued and has not asked the bank yet. It is not a fault.
 *
 * NO SERVER PROSE. `reason` and `outcome.result` are machine values; this
 * panel words them in its own en + dv copy, exactly as the two apps do.
 */
export const TransferProgressKindSchema = z.enum([
  "settlement_payment",
  "wallet_top_up",
]);
export type TransferProgressKind = z.infer<typeof TransferProgressKindSchema>;

/**
 * The row's own lifecycle. `unknown` is never on the wire — it is where an
 * unrecognised value lands so a poll cannot be killed by a state this build
 * has not heard of.
 */
export const TransferMatchStateSchema = z
  .enum(["pending", "matched", "rejected", "unknown"])
  .catch("unknown");
export type TransferMatchState = z.infer<typeof TransferMatchStateSchema>;

/**
 * Why nothing is being watched — the pollers' own short-circuit order, so
 * the reason is the TRUE one and not merely the last gate:
 *  - `terminal` — already matched or rejected; there is an outcome, not a wait;
 *  - `auto_verify_off` — the platform switch is off, so nothing auto-verifies
 *    anywhere today;
 *  - `no_verify_profile` — the account the merchant paid into has no active
 *    read profile; a bank nobody watches is never auto-verified;
 *  - `never_watched` — no watch was ever started on this transfer: it was
 *    uploaded while the switch was down, so no poll job exists for it and
 *    none ever will. NOT the same as `window_expired`, and it must never be
 *    worded as a check that ran and found nothing;
 *  - `window_expired` — the watch ran and lapsed; it belongs to the admin
 *    queue now;
 *  - `unknown` — never on the wire; an unrecognised reason degrades to here,
 *    and it must read as "not watched", the SAFE side of the honesty rule.
 *
 * All of them except `terminal` mean the same sentence to a merchant: our
 * team will confirm your transfer shortly.
 */
export const TRANSFER_WATCH_REASONS = [
  "auto_verify_off",
  "no_verify_profile",
  "never_watched",
  "window_expired",
  "terminal",
  "unknown",
] as const;
export const TransferWatchReasonSchema = z.enum(TRANSFER_WATCH_REASONS);
export type TransferWatchReason = (typeof TRANSFER_WATCH_REASONS)[number];

/**
 * The half of the payload that is identical on both flows — one object
 * literal, spread into both schemas, so the two screens share one parser and
 * the shapes cannot drift apart. Only `kind` and `outcome` differ.
 *
 * Every tolerant `.catch` here degrades to the SAFE reading: not watching,
 * nothing counted, nothing decided.
 */
const transferProgressShape = {
  /** Payment id on a settlement, claim id on a top-up — not the batch id. */
  id: z.number().int(),
  /** The batch a settlement payment belongs to; null on a top-up. */
  settlement_id: z.number().int().nullable(),
  state: TransferMatchStateSchema,
  /** What the merchant said they transferred. */
  amount_laari: z.number().int(),
  amount_mvr: z.string(),
  /** THE server fact. Never infer this; a bad value reads as false. */
  watching: z.boolean().catch(false),
  /** Null exactly when `watching` is true. */
  reason: TransferWatchReasonSchema.nullable().catch("unknown"),
  watch_started_at: z.string().nullable().catch(null),
  /** The instant the watch gives up. Count down against `checked_at`. */
  watch_until: z.string().nullable().catch(null),
  /** How many times the bank has actually been asked. */
  attempts: z.number().int().catch(0),
  /** True only when the BANK matched it. An admin's match leaves it false. */
  auto_matched: z.boolean().catch(false),
  decided_at: z.string().nullable().catch(null),
  /**
   * The SERVER's clock at the moment of this read. The countdown is
   * `watch_until − checked_at`, never `watch_until − Date.now()`: a handset
   * or a laptop whose clock is minutes out must not invent or eat time.
   */
  checked_at: z.string(),
};

/**
 * THE MERCHANT'S CLAIM AND THE BANK'S FACT, carried by BOTH outcomes
 * (owner, 2026-08-25). One object literal spread into both, exactly as the
 * server builds it once — this pair is precisely what a merchant is reading
 * when the two numbers disagree, and two copies of it would drift.
 *
 * `claimed_laari` is what they typed. `received_laari` is what the bank
 * actually sent, and is what was credited or allocated. It is NULL where no
 * bank figure is known — a payment an admin reconciled by hand off a
 * statement — and `amount_differs` is then FALSE, because an unknown is not
 * a discrepancy: a screen must never announce a mismatch it cannot name both
 * sides of.
 *
 * The envelope's `amount_laari` above is the CLAIM, deliberately unchanged
 * by a match, so a client reading only the envelope cannot mistake one
 * figure for the other.
 */
const transferClaimAndFactShape = {
  claimed_laari: z.number().int(),
  claimed_mvr: z.string(),
  received_laari: z.number().int().nullable(),
  received_mvr: z.string().nullable(),
  amount_differs: z.boolean(),
};

/** `unknown` is never on the wire; an unrecognised result lands there. */
export const SettlementProgressResultSchema = z
  .enum(["settled", "partially_settled", "rejected", "unknown"])
  .catch("unknown");
export type SettlementProgressResult = z.infer<
  typeof SettlementProgressResultSchema
>;

/**
 * What the BATCH became once the payment stopped being pending.
 *
 * `partially_settled` is reported honestly with what is STILL OWED: §7
 * allocates whole lines only, so a merchant who transferred less than the
 * due has a real remainder to send and must be given the number rather than
 * congratulated. `amount_outstanding_laari` is exactly that further transfer
 * — `amount_due` is already net of §7 credits and the prompt-payment
 * discount — and it is forced to 0 on a settled batch and on a cancelled one
 * (a refused receipt releases the lines; nothing is owed on a dead
 * reference).
 *
 * `amount_received_laari` is the BATCH's running total, not this payment's
 * amount — that is `amount_laari` at the top level.
 */
export const SettlementPaymentOutcomeSchema = z.object({
  result: SettlementProgressResultSchema,
  /**
   * THIS payment's two figures. The bank's is what funded the batch; the
   * merchant's is what they typed. A screen that printed only the claim
   * would explain a `partially_settled` batch with the very number that
   * does not account for it.
   */
  ...transferClaimAndFactShape,
  /** The raw §6 state, for a panel that would rather branch on the batch. */
  settlement_state: SettlementStateSchema,
  reference: z.string(),
  amount_received_laari: z.number().int(),
  amount_received_mvr: z.string(),
  amount_outstanding_laari: z.number().int(),
  amount_outstanding_mvr: z.string(),
  /**
   * Why the receipt was refused, verbatim from the admin who refused it.
   * The wire says `rejected_reason` on BOTH flows even though the settlement
   * column is `rejection_reason`, so one parser serves both.
   */
  rejected_reason: z.string().nullable(),
});
export type SettlementPaymentOutcome = z.infer<
  typeof SettlementPaymentOutcomeSchema
>;

/** `unknown` is never on the wire; an unrecognised result lands there. */
export const WalletTopUpProgressResultSchema = z
  .enum(["credited", "rejected", "unknown"])
  .catch("unknown");
export type WalletTopUpProgressResult = z.infer<
  typeof WalletTopUpProgressResultSchema
>;

/**
 * What the WALLET became. `credited_laari` is 0 on a rejection, and
 * `balance_laari` is the balance AT READ TIME rather than a snapshot taken
 * when the credit landed — if the hourly auto-settle spent it in between,
 * "balance now" has to be true.
 *
 * `credited_laari` is WHAT WENT IN, not what was asked for: on a matched
 * claim it equals `received_laari` where the bank gave a figure. When
 * `amount_differs` is true, a screen that congratulates the merchant on the
 * claim while a smaller sum sits in the wallet is telling them something
 * untrue — say the arrived figure, and say it was not the one they entered.
 */
export const WalletTopUpOutcomeSchema = z.object({
  result: WalletTopUpProgressResultSchema,
  credited_laari: z.number().int(),
  credited_mvr: z.string(),
  ...transferClaimAndFactShape,
  balance_laari: z.number().int(),
  balance_mvr: z.string(),
  rejected_reason: z.string().nullable(),
});
export type WalletTopUpOutcome = z.infer<typeof WalletTopUpOutcomeSchema>;

export const SettlementPaymentProgressSchema = z.object({
  kind: z.literal("settlement_payment"),
  ...transferProgressShape,
  /** Null while pending — an outcome that does not exist is never invented. */
  outcome: SettlementPaymentOutcomeSchema.nullable(),
});
export type SettlementPaymentProgress = z.infer<
  typeof SettlementPaymentProgressSchema
>;

export const WalletTopUpProgressSchema = z.object({
  kind: z.literal("wallet_top_up"),
  ...transferProgressShape,
  /** Null while pending. */
  outcome: WalletTopUpOutcomeSchema.nullable(),
});
export type WalletTopUpProgress = z.infer<typeof WalletTopUpProgressSchema>;

/** Either flow, discriminated on `kind` — for code that handles both. */
export const TransferProgressSchema = z.discriminatedUnion("kind", [
  SettlementPaymentProgressSchema,
  WalletTopUpProgressSchema,
]);
export type TransferProgress = z.infer<typeof TransferProgressSchema>;

export const SettlementPaymentProgressResponseSchema = dataWrapped(
  SettlementPaymentProgressSchema,
);
export type SettlementPaymentProgressResponse = z.infer<
  typeof SettlementPaymentProgressResponseSchema
>;

export const WalletTopUpProgressResponseSchema = dataWrapped(
  WalletTopUpProgressSchema,
);
export type WalletTopUpProgressResponse = z.infer<
  typeof WalletTopUpProgressResponseSchema
>;

/**
 * GET /api/merchant/settlements/{id}/payment-progress — `settlements.view`.
 *
 * Takes a SETTLEMENT id and reports the batch's NEWEST bank payment, which
 * is the receipt the merchant just uploaded rather than the one that landed
 * last week. A batch with no bank payment at all (settled from the wallet,
 * or built by an admin and not yet paid) has no transfer to report on and
 * answers 404 — the same 404 another store's batch answers, because a 403
 * would confirm the row exists.
 */
export function getSettlementPaymentProgress(
  settlementId: number,
  options: RequestOptions = {},
): Promise<SettlementPaymentProgressResponse> {
  return apiFetch(
    `/api/merchant/settlements/${settlementId}/payment-progress`,
    SettlementPaymentProgressResponseSchema,
    { signal: options.signal },
  );
}

/**
 * GET /api/merchant/wallet/top-ups/{id}/progress — `wallet.view`.
 *
 * Takes a TOP-UP CLAIM id (the `id` on the claim `createMerchantWalletTopUp`
 * answered, or one from the wallet payload's `pending_top_ups`). Another
 * store's claim is a plain 404.
 */
export function getWalletTopUpProgress(
  topUpId: number,
  options: RequestOptions = {},
): Promise<WalletTopUpProgressResponse> {
  return apiFetch(
    `/api/merchant/wallet/top-ups/${topUpId}/progress`,
    WalletTopUpProgressResponseSchema,
    { signal: options.signal },
  );
}

/**
 * May the screen run a progress indicator, and should the poll continue?
 * Both questions have the same answer, and it is the server's, not ours.
 *
 * The `outcome` guard is belt and braces: a decided transfer is never
 * watched, so a payload claiming both is contradictory and the outcome —
 * the thing that actually happened — wins.
 */
export function isTransferWatched(progress: TransferProgress): boolean {
  return progress.watching && progress.outcome === null;
}

/**
 * Whole seconds left on the watch, measured on the SERVER's clock
 * (`watch_until − checked_at`) so a wrong local clock cannot invent or eat
 * time. 0 when nothing is being watched, when the window has no end
 * recorded, or when either stamp is unparseable — a countdown that cannot be
 * computed honestly shows nothing rather than a guess.
 */
export function transferWatchSecondsLeft(progress: TransferProgress): number {
  if (!isTransferWatched(progress) || progress.watch_until === null) {
    return 0;
  }
  const until = Date.parse(progress.watch_until);
  const checked = Date.parse(progress.checked_at);
  if (Number.isNaN(until) || Number.isNaN(checked)) {
    return 0;
  }
  return Math.max(0, Math.floor((until - checked) / 1000));
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
  "rate_below_advertised",
  "rate_not_priced",
  "lines_sum_mismatch",
  "unknown_category",
  "inactive_category",
  "duplicate_category_line",
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
  if (!(error instanceof ApiError) || typeof error.body !== "object") {
    return null;
  }
  const advertised = (
    error.body as { advertised_cashback_rate_percent?: unknown } | null
  )?.advertised_cashback_rate_percent;
  return typeof advertised === "string" ? advertised : null;
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
  return apiFetch("/api/merchant/credits", CreateCreditResponseSchema, {
    method: "POST",
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
export const CreateProductCategoryRequestSchema = z.discriminatedUnion("mode", [
  productCategoryRequestBase.extend({ mode: z.literal("excluded") }),
  productCategoryRequestBase.extend({
    mode: z.literal("rate"),
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
    "/api/merchant/product-categories",
    ProductCategoryListResponseSchema,
    { signal: options.signal },
  );
}

/**
 * POST /api/merchant/product-categories — `product_categories.create`,
 * trading stores only (201).
 */
export function createMerchantProductCategory(
  body: CreateProductCategoryRequest,
  options: RequestOptions = {},
): Promise<ProductCategoryResponse> {
  return apiFetch(
    "/api/merchant/product-categories",
    ProductCategoryResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/**
 * PATCH /api/merchant/product-categories/{id} — `product_categories.edit`.
 * There is deliberately no DELETE: deactivation (`active: false`) is the only
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
    { method: "PATCH", body, signal: options.signal },
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

/** GET /api/merchant/promotions — newest first; `promotions.view`. */
export function listMerchantPromotions(
  params: { status?: PromotionStatus } = {},
  options: RequestOptions = {},
): Promise<MerchantPromotionListResponse> {
  const query =
    params.status !== undefined
      ? `?status=${encodeURIComponent(params.status)}`
      : "";
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

/** POST /api/merchant/promotions — creates a draft (201). `promotions.create`. */
export function createMerchantPromotion(
  body: CreatePromotionRequest,
  options: RequestOptions = {},
): Promise<MerchantPromotionWithPreviewResponse> {
  return apiFetch(
    "/api/merchant/promotions",
    MerchantPromotionWithPreviewResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/**
 * POST /api/merchant/promotions/{id}/publish — draft → published.
 * `promotions.publish`, held separately from `promotions.create` because
 * this is the irreversible half: drafting a promotion costs nothing and
 * publishing one binds the store to it in public.
 *
 * Once published the promotion is IMMUTABLE for its stated duration —
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
    { method: "POST", signal: options.signal },
  );
}

/**
 * POST /api/merchant/promotions/{id}/cancel — withdraws a DRAFT.
 * `promotions.cancel`. A published promotion can never be cancelled (409) —
 * that would be the forbidden early end.
 */
export function cancelMerchantPromotion(
  id: number,
  options: RequestOptions = {},
): Promise<MerchantPromotionResponse> {
  return apiFetch(
    `/api/merchant/promotions/${id}/cancel`,
    MerchantPromotionResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Settings — profile (`profile.view` / `profile.edit`)
// ---------------------------------------------------------------------------

/**
 * The merchant profile. `name`, `slug` and `status` are
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
  "store_not_approved",
  "store_not_trading",
]);
export type MerchantWriteGateCode = z.infer<typeof MerchantWriteGateCodeSchema>;

export const MerchantProfileSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** The store's own name in Thaana; null when it has not supplied one. */
  name_dv: z.string().nullable().catch(null),
  slug: z.string(),
  status: MerchantStatusSchema,
  /**
   * Whether the store is on the app RIGHT NOW. Independent of `status`: a
   * store may be `active` and unpublished, because publication is the
   * merchant's own switch and status is the account lifecycle. Read this,
   * never infer it from status.
   */
  published: z.boolean().catch(true),
  /** When the store took itself off; null while it is published. */
  unpublished_at: z.string().nullable().catch(null),
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
  /**
   * The store's own words about itself, shown to shoppers on its page — a
   * public CLAIM, so on a live store an edit queues for review like the name
   * or the category. Capped at STORE_DESCRIPTION_MAX_WORDS words, not
   * characters.
   */
  description: z.string().nullable(),
  contact_email: z.string().nullable(),
  contact_phone: z.string().nullable(),
  support_phone: z.string().nullable().catch(null),
  website_url: z.string().nullable().catch(null),
  /**
   * MR9. The store's own public claims waiting on an admin, with the
   * proposed values — null when nothing is queued. Everything ABOVE stays
   * the LIVE row: what a shopper reads until the change is approved, and
   * therefore what the form shows.
   */
  pending_change: MerchantChangeRequestSchema.nullable(),
});
export type MerchantProfile = z.infer<typeof MerchantProfileSchema>;

export const MerchantProfileResponseSchema = dataWrapped(MerchantProfileSchema);
export type MerchantProfileResponse = z.infer<
  typeof MerchantProfileResponseSchema
>;

export const UpdateMerchantProfileRequestSchema = z.object({
  /**
   * The Thaana name. Editable even though `name` is not: this
   * is a translation of the display name rather than the store's identity,
   * and nothing (the slug included) is derived from it.
   */
  /**
   * Both names are editable; the SLUG never follows either. It is the
   * address on every link and QR code already in circulation, so a rebrand
   * changes the words and leaves the door where it was.
   */
  name: z.string().min(2).max(120).optional(),
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
  /**
   * The store's description. No character cap here on purpose — the API's
   * ceiling is STORE_DESCRIPTION_MAX_WORDS *words* (App\Rules\MaxWords), so
   * a length in characters would refuse text the server accepts.
   */
  description: z.string().nullable().optional(),
  contact_email: z.email().max(255).nullable().optional(),
  contact_phone: z.string().max(32).nullable().optional(),
  /** The number shoppers ring; the panel offers "same as contact" as one tick. */
  support_phone: z.string().max(32).nullable().optional(),
  /** A bare domain is fine — the API adds https:// and validates the result. */
  website_url: z.string().max(255).nullable().optional(),
});
export type UpdateMerchantProfileRequest = z.infer<
  typeof UpdateMerchantProfileRequestSchema
>;

/** GET /api/merchant/profile — `profile.view`. */
export function getMerchantProfile(
  options: RequestOptions = {},
): Promise<MerchantProfileResponse> {
  return apiFetch("/api/merchant/profile", MerchantProfileResponseSchema, {
    signal: options.signal,
  });
}

/**
 * The two shapes a profile PATCH can answer with (MR9):
 *   202 — a gated CLAIM actually moved, so it queues; the instant half is
 *         already applied and is reflected in the `profile` beside it;
 *   200 — only instant keys moved, nothing gated DIFFERED from the live row
 *         (both panels PATCH the whole form), or the store is not live.
 * The queued variant is tried first and can never swallow a plain profile:
 * it demands a `change_request` no profile body carries.
 */
export const UpdateMerchantProfileResponseSchema = z.union([
  z.object({
    data: QueuedChangeSchema.extend({ profile: MerchantProfileSchema }),
  }),
  MerchantProfileResponseSchema,
]);

/** A profile save, with the two answers flattened into one result. */
export interface MerchantProfileSaveResult {
  /** The queued request when a claim moved (202), null on a plain save. */
  queued: MerchantChangeRequest | null;
  /** The LIVE profile after the save — the instant half already applied. */
  profile: MerchantProfile;
}

/**
 * PATCH /api/merchant/profile — partial update; omitted keys are untouched.
 * `profile.edit` plus an approved store.
 *
 * A live store's public claims (name, name_dv, category, channel,
 * eligibility_basis, description, website_url) do NOT apply here: they queue
 * for admin review and come back as `queued`. The gate is fail-closed
 * server-side — everything this endpoint validates except the three contact
 * keys is a claim — so a field added tomorrow queues too.
 *
 * Contact email, contact phone and support phone apply in the same request —
 * a wrong number means customers cannot reach the store, so holding that fix
 * for a reviewer would only prolong the harm (PLAN-merchant-app.md §MR9).
 */
export async function updateMerchantProfile(
  body: UpdateMerchantProfileRequest,
  options: RequestOptions = {},
): Promise<MerchantProfileSaveResult> {
  const response = await apiFetch(
    "/api/merchant/profile",
    UpdateMerchantProfileResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );

  return "change_request" in response.data
    ? { queued: response.data.change_request, profile: response.data.profile }
    : { queued: null, profile: response.data };
}

// ---------------------------------------------------------------------------
// Settings — bank account (`bank_account.view` / `bank_account.update`)
// ---------------------------------------------------------------------------

/**
 * The merchant's own bank identity, used for matching INBOUND settlement
 * payments (and future wallet withdrawals) — never a payout destination:
 * money flows merchant → platform.
 *
 * All three are nullable because the READ is reachable before the identity
 * has ever been supplied — a store that has not filled it in reads back
 * three nulls rather than 404. The WRITE still demands all three together.
 */
export const MerchantBankAccountSchema = z.object({
  bank_name: z.string().nullable(),
  bank_account: z.string().nullable(),
  bank_account_name: z.string().nullable(),
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
  bank_name: BankSlugSchema,
  bank_account: z.string().min(1).max(64),
  bank_account_name: z.string().min(1).max(255),
});
export type UpdateMerchantBankAccountRequest = z.infer<
  typeof UpdateMerchantBankAccountRequestSchema
>;

/**
 * GET /api/merchant/bank-account — `bank_account.view` (D6). Seeing which
 * account the store is matched against is bookkeeping; repointing it is the
 * most consequential change in the panel, so the read is its own permission
 * and a role can hold it without holding `bank_account.update`.
 */
export function getMerchantBankAccount(
  options: RequestOptions = {},
): Promise<MerchantBankAccountResponse> {
  return apiFetch(
    "/api/merchant/bank-account",
    MerchantBankAccountResponseSchema,
    { signal: options.signal },
  );
}

/**
 * PATCH /api/merchant/bank-account — `bank_account.update`; all three fields
 * together.
 */
export function updateMerchantBankAccount(
  body: UpdateMerchantBankAccountRequest,
  options: RequestOptions = {},
): Promise<MerchantBankAccountResponse> {
  return apiFetch(
    "/api/merchant/bank-account",
    MerchantBankAccountResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Settings — branches (`branches.view` / `.create` / `.edit` / `.delete`)
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
  meta: z.object({
    /**
     * MR9: the branch changes this store has waiting on a reviewer, oldest
     * first — creates (no row exists yet), updates and removals (the row is
     * still live). `data` above is always the ESTATE AS IT STANDS.
     */
    pending_changes: z.array(MerchantChangeRequestSchema),
  }),
});
export type MerchantBranchListResponse = z.infer<
  typeof MerchantBranchListResponseSchema
>;

/**
 * POST /api/merchant/publication — the store's own on/off switch. No review
 * queue in either direction (owner decision 2026-08-18).
 *
 * `customers_notified` reports whether THIS call reached anyone: the
 * platform sends at most one pause and one resume message per store per day,
 * so a second toggle is honoured but silent, and the panel says so rather
 * than letting the merchant assume a broadcast went out.
 */
export const MerchantPublicationResponseSchema = dataWrapped(
  z.object({
    published: z.boolean(),
    unpublished_at: z.string().nullable(),
    customers_notified: z.boolean(),
  }),
);
export type MerchantPublicationResponse = z.infer<
  typeof MerchantPublicationResponseSchema
>;

/**
 * GET /api/merchant/branches/reverse-geocode — the pin, in words.
 *
 * `address` is null when the geocoder had nothing for that spot or was
 * unreachable; that is an ordinary answer, not an error. The merchant types
 * the address instead, and the field they type into is the authority either
 * way — this only ever pre-fills it.
 */
export const ReverseGeocodeResponseSchema = dataWrapped(
  z.object({ address: z.string().nullable() }),
);
export type ReverseGeocodeResponse = z.infer<
  typeof ReverseGeocodeResponseSchema
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
  /**
   * REQUIRED on create (owner decision 2026-08-18). A branch on the map with
   * no address is a pin a customer cannot read — the map app we hand off to
   * titles a bare coordinate with the coordinate itself. Optional on UPDATE
   * (the partial below), so a PATCH that only moves the pin need not resend
   * it; the server refuses an empty one either way.
   */
  address: z.string().min(1).max(1000),
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
  return apiFetch("/api/merchant/branches", MerchantBranchListResponseSchema, {
    signal: options.signal,
  });
}

/**
 * MR9: every branch write is a public claim — a branch is an address a
 * shopper travels to — so for a LIVE store it QUEUES (202) instead of
 * applying, and the estate moves only when an admin approves. A store that
 * is not live still writes straight through (201/200/204).
 */
const BranchWriteResponseSchema = z.union([
  z.object({ data: QueuedChangeSchema }),
  MerchantBranchResponseSchema,
]);

/** A branch write, with the queued and applied answers flattened into one. */
export interface MerchantBranchSaveResult {
  /** The queued request when the write is waiting on a reviewer. */
  queued: MerchantChangeRequest | null;
  /** The branch as it now stands — null while the write is queued. */
  branch: MerchantBranch | null;
}

function branchSaveResult(
  response: z.infer<typeof BranchWriteResponseSchema>,
): MerchantBranchSaveResult {
  return "change_request" in response.data
    ? { queued: response.data.change_request, branch: null }
    : { queued: null, branch: response.data };
}

/**
 * POST /api/merchant/publication — take the store off the app, or put it
 * back. No review either way; see the response schema for the daily cap on
 * customer messages.
 */
export async function setMerchantPublication(
  published: boolean,
  options: RequestOptions = {},
): Promise<MerchantPublicationResponse["data"]> {
  const response = await apiFetch(
    "/api/merchant/publication",
    MerchantPublicationResponseSchema,
    { method: "POST", body: { published }, signal: options.signal },
  );

  return response.data;
}

/**
 * GET /api/merchant/branches/reverse-geocode — the words for a dropped pin.
 * Returns null when nothing is known about that spot; the caller pre-fills
 * an editable field with it and never blocks on it.
 */
export async function reverseGeocodeBranchPin(
  lat: number,
  lng: number,
  options: RequestOptions = {},
): Promise<string | null> {
  const response = await apiFetch(
    `/api/merchant/branches/reverse-geocode?lat=${lat}&lng=${lng}`,
    ReverseGeocodeResponseSchema,
    { signal: options.signal },
  );

  return response.data.address;
}

/** POST /api/merchant/branches — creates a branch (201), or queues one (202). */
export async function createMerchantBranch(
  body: CreateMerchantBranchRequest,
  options: RequestOptions = {},
): Promise<MerchantBranchSaveResult> {
  return branchSaveResult(
    await apiFetch("/api/merchant/branches", BranchWriteResponseSchema, {
      method: "POST",
      body,
      signal: options.signal,
    }),
  );
}

/**
 * PATCH /api/merchant/branches/{id} — partial update, or a queued one. A
 * save that moved nothing answers 200 with the untouched branch: the panel
 * PATCHes the whole dialog, so re-saving an unchanged branch must not park
 * it in a review queue.
 */
export async function updateMerchantBranch(
  id: number,
  body: UpdateMerchantBranchRequest,
  options: RequestOptions = {},
): Promise<MerchantBranchSaveResult> {
  return branchSaveResult(
    await apiFetch(`/api/merchant/branches/${id}`, BranchWriteResponseSchema, {
      method: "PATCH",
      body,
      signal: options.signal,
    }),
  );
}

/**
 * DELETE /api/merchant/branches/{id} — 204 when it applies, 202 with the
 * queued request when it waits for review (the branch stays live meanwhile).
 * A branch referenced by transactions or branch-scoped promotions is history
 * that must keep resolving: the server answers 409 with code
 * `branch_referenced` (thrown here as ApiError) — at SUBMIT, not only at
 * approval — and the soft alternative is simply to stop using it.
 */
export async function deleteMerchantBranch(
  id: number,
  options: RequestOptions = {},
): Promise<MerchantChangeRequest | null> {
  const response = await apiFetch(
    `/api/merchant/branches/${id}`,
    z.object({ data: QueuedChangeSchema }).optional(),
    { method: "DELETE", signal: options.signal },
  );

  return response?.data.change_request ?? null;
}

// ---------------------------------------------------------------------------
// Settings — permissions catalogue (`roles.view`)
// ---------------------------------------------------------------------------

/**
 * The closed set of things a merchant panel account can be allowed to do
 * (PLAN §13b). Mirrors App\Domain\MerchantAccess\Permission in catalogue
 * order — the order the roles screen renders within each group.
 *
 * Slugs are `group.action` with a DOT. Vendor token abilities
 * (VENDOR_ABILITIES below) keep `group:action` with a COLON, and the
 * separation is load-bearing rather than cosmetic: they are different axes —
 * a session-authenticated merchant user carries a Sanctum TransientToken
 * whose ability check answers true for everything — so a slug that reads
 * wrong at a glance is the cheapest way to catch one being passed to the
 * other.
 */
export const MERCHANT_PERMISSIONS = [
  "credits.create",
  "credits.custom_rate",
  "customers.lookup",
  "transactions.view",
  "transactions.amend",
  "transactions.cancel",
  "rate.view",
  "rate.update",
  "promotions.view",
  "promotions.create",
  "promotions.publish",
  "promotions.cancel",
  "product_categories.view",
  "product_categories.create",
  "product_categories.edit",
  "settlements.view",
  "settlements.preview",
  "settlements.create",
  "settlements.receipt_add",
  "wallet.view",
  "wallet.settle",
  // Claiming a bank transfer INTO the wallet (owner, 2026-08-24). Granted
  // to every role that held wallet.settle when it landed; in the Manager
  // preset; owners hold it through the wildcard.
  "wallet.top_up",
  "profile.view",
  "profile.edit",
  "branding.update",
  "branches.view",
  "branches.create",
  "branches.edit",
  "branches.delete",
  "bank_account.view",
  "bank_account.update",
  "preferences.update",
  "staff.view",
  "staff.invite",
  "staff.edit",
  "roles.view",
  "roles.manage",
  "api_credentials.view",
  "api_credentials.create",
  "api_credentials.revoke",
  "setup.view",
  "setup.edit",
  "setup.submit",
  // Pausing the store on the app, and running the marketplace shop. Both
  // separated from profile.edit on purpose: going dark to every customer,
  // and committing the business to selling online, are not the same
  // authority as editing a phone number.
  "store.publication",
  "marketplace.manage",
] as const;
export const MerchantPermissionSchema = z.enum(MERCHANT_PERMISSIONS);
export type MerchantPermission = (typeof MERCHANT_PERMISSIONS)[number];

/**
 * Narrows a permission slug off the wire. Every permission ARRAY in this
 * file is `string[]` rather than this enum, deliberately: the catalogue is
 * SERVED (see listMerchantPermissions), so a role can legitimately hold — and
 * the roles screen can legitimately render and send back — a slug this build
 * predates. Type the panel's own gate calls as MerchantPermission for the
 * compile-time check, and test wire values with this before labelling them.
 */
export function isMerchantPermission(
  value: string,
): value is MerchantPermission {
  return (MERCHANT_PERMISSIONS as readonly string[]).includes(value);
}

/** One permission as the catalogue endpoint describes it. */
export const MerchantPermissionEntrySchema = z.object({
  slug: z.string(),
  /** The ACT, in prose, worded by the server: "Cancel a transaction". */
  label: z.string(),
  /** The owning group's slug — the same value as its group's `slug`. */
  group: z.string(),
});
export type MerchantPermissionEntry = z.infer<
  typeof MerchantPermissionEntrySchema
>;

/** One heading on the roles screen, with the permissions that sit under it. */
export const MerchantPermissionGroupSchema = z.object({
  slug: z.string(),
  label: z.string(),
  permissions: z.array(MerchantPermissionEntrySchema),
});
export type MerchantPermissionGroup = z.infer<
  typeof MerchantPermissionGroupSchema
>;

export const MerchantPermissionCatalogueResponseSchema = z.object({
  data: z.object({ groups: z.array(MerchantPermissionGroupSchema) }),
});
export type MerchantPermissionCatalogueResponse = z.infer<
  typeof MerchantPermissionCatalogueResponseSchema
>;

/**
 * GET /api/merchant/permissions — `roles.view`. The catalogue with its
 * groups and its wording, published rather than hardcoded here (D8) so a
 * permission added by a later deploy renders under the right heading in a
 * panel build that predates it. Render the checkboxes from THIS, not from
 * MERCHANT_PERMISSIONS — that const exists for compile-time safety on the
 * panel's own gate calls, and a screen driven by it would silently omit the
 * checkbox for a permission the server is already enforcing.
 */
export function listMerchantPermissions(
  options: RequestOptions = {},
): Promise<MerchantPermissionCatalogueResponse> {
  return apiFetch(
    "/api/merchant/permissions",
    MerchantPermissionCatalogueResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Settings — roles (`roles.view` / `roles.manage`)
// ---------------------------------------------------------------------------

/**
 * One of the merchant's own roles. Roles are per-store, so `name` is the
 * shop's own word for the job — "Shift lead", "Accounts" — and `name_dv` is
 * that word in Thaana; neither is an i18n key and neither is safe to compare
 * against. `slug` is what identifies the three seeded presets and survives a
 * rename.
 *
 * `permissions` is the RESOLVED set, with the owner's wildcard already
 * expanded against the catalogue (D3): the owner role stores an empty list
 * because its authority is the FLAG, so a screen rendering the stored column
 * would draw the most powerful role in the store with every box unticked.
 *
 * `is_owner` is frozen apart from its name (D9) and `is_system` marks the
 * three presets, so the panel can grey out what it must not offer.
 */
export const MerchantRoleSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  name_dv: z.string().nullable(),
  slug: z.string(),
  permissions: z.array(z.string()),
  is_owner: z.boolean(),
  is_system: z.boolean(),
  /**
   * How many accounts stand on this role — the reason a delete is refused.
   * Every role response counts it; a body without it is a server regression
   * worth failing on rather than rendering a blank column.
   */
  staff_count: z.number().int(),
});
export type MerchantRole = z.infer<typeof MerchantRoleSchema>;

/**
 * The role as it appears NEXT TO a person — on the staff list and on the
 * signed-in account. Just enough to PRINT it and to know it is the frozen
 * one; the permission set belongs to the roles screen, and repeating the
 * whole catalogue against every cashier row would be the wrong wire.
 *
 * Nullable because nothing has authority without a role: the panel renders
 * the gap rather than inventing a name. Never gate on it — gate on the
 * resolved permission array from `/me`.
 */
export const MerchantRoleSummarySchema = z.object({
  id: z.number().int(),
  name: z.string(),
  name_dv: z.string().nullable(),
  is_owner: z.boolean(),
});
export type MerchantRoleSummary = z.infer<typeof MerchantRoleSummarySchema>;

export const MerchantRoleListResponseSchema = z.object({
  data: z.array(MerchantRoleSchema),
});
export type MerchantRoleListResponse = z.infer<
  typeof MerchantRoleListResponseSchema
>;

export const MerchantRoleResponseSchema = dataWrapped(MerchantRoleSchema);
export type MerchantRoleResponse = z.infer<typeof MerchantRoleResponseSchema>;

/**
 * Refusals the roles screen must tell apart, carried as `code` (read them
 * with `apiErrorCode`). They need different repairs, which is the whole
 * reason they are distinct — a screen that can only print prose can offer
 * none of them:
 *
 *  - `permission_not_held` (403) — you cannot give a role a permission you
 *    do not hold yourself (D5). The body's `permissions` array names the
 *    offending slugs, so the screen can point at the checkboxes;
 *  - `owner_role_not_delegable` (403) — only an owner hands out the owner
 *    role;
 *  - `cannot_edit_own_role` (403) — otherwise `roles.manage` silently equals
 *    owner;
 *  - `owner_role_frozen` (409) — the owner role always holds everything, so
 *    its permissions cannot be edited. It can still be renamed;
 *  - `owner_role_undeletable` (409) — every store keeps one;
 *  - `role_in_use` (409) — staff still stand on it; the body's `staff_count`
 *    says how many. Move them first;
 *  - `role_cap_reached` (422) — 20 roles per store.
 */
export const MERCHANT_ROLE_ERROR_CODES = [
  "permission_not_held",
  "owner_role_not_delegable",
  "cannot_edit_own_role",
  "owner_role_frozen",
  "owner_role_undeletable",
  "role_in_use",
  "role_cap_reached",
] as const;
export const MerchantRoleErrorCodeSchema = z.enum(MERCHANT_ROLE_ERROR_CODES);
export type MerchantRoleErrorCode = (typeof MERCHANT_ROLE_ERROR_CODES)[number];

export const CreateMerchantRoleRequestSchema = z.object({
  name: z.string().min(2).max(80),
  /** The Thaana label. Optional — a store that leaves it blank shows `name`. */
  name_dv: z.string().max(80).nullable().optional(),
  /**
   * Required but allowed to be EMPTY: a role holding nothing yet is a
   * legitimate starting point on a screen built out of checkboxes.
   *
   * Typed as strings rather than the enum because the checkboxes come from
   * the served catalogue, which may name a permission this build predates.
   * The server refuses anything outside its own catalogue (422) and anything
   * the caller does not hold themselves (403 `permission_not_held`).
   */
  permissions: z.array(z.string()),
});
export type CreateMerchantRoleRequest = z.infer<
  typeof CreateMerchantRoleRequestSchema
>;

/**
 * Every key optional and only the ones SENT are applied — `name_dv` is
 * nullable, so "clear it" and "leave it alone" are different requests that
 * would otherwise both arrive as null. Sending `permissions` REPLACES the
 * set; there is no add/remove.
 */
export const UpdateMerchantRoleRequestSchema = z.object({
  name: z.string().min(2).max(80).optional(),
  name_dv: z.string().max(80).nullable().optional(),
  permissions: z.array(z.string()).optional(),
});
export type UpdateMerchantRoleRequest = z.infer<
  typeof UpdateMerchantRoleRequestSchema
>;

/** GET /api/merchant/roles — the store's roles in id order, each counted. */
export function listMerchantRoles(
  options: RequestOptions = {},
): Promise<MerchantRoleListResponse> {
  return apiFetch("/api/merchant/roles", MerchantRoleListResponseSchema, {
    signal: options.signal,
  });
}

/** POST /api/merchant/roles — `roles.manage` (201). */
export function createMerchantRole(
  body: CreateMerchantRoleRequest,
  options: RequestOptions = {},
): Promise<MerchantRoleResponse> {
  return apiFetch("/api/merchant/roles", MerchantRoleResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

/** PATCH /api/merchant/roles/{id} — partial; omitted keys are untouched. */
export function updateMerchantRole(
  id: number,
  body: UpdateMerchantRoleRequest,
  options: RequestOptions = {},
): Promise<MerchantRoleResponse> {
  return apiFetch(`/api/merchant/roles/${id}`, MerchantRoleResponseSchema, {
    method: "PATCH",
    body,
    signal: options.signal,
  });
}

/**
 * DELETE /api/merchant/roles/{id} — 204 on success. A role with staff on it
 * answers 409 `role_in_use` and the owner role answers 409
 * `owner_role_undeletable`; both arrive here as ApiError.
 */
export async function deleteMerchantRole(
  id: number,
  options: RequestOptions = {},
): Promise<void> {
  await apiFetch(`/api/merchant/roles/${id}`, z.undefined(), {
    method: "DELETE",
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Settings — staff (`staff.view` / `staff.invite` / `staff.edit`)
// ---------------------------------------------------------------------------

/**
 * A merchant panel account. The role is an OBJECT, not a name: names are the
 * store's own words now, so a string would be neither stable enough to
 * compare nor complete enough to print, and the id is what the edit form
 * patches back.
 */
export const MerchantStaffSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
  role: MerchantRoleSummarySchema.nullable(),
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
   * One of the STORE'S OWN roles (a foreign id is refused exactly as a
   * missing one). Required: with a per-store role table there is no tier
   * left to default to, and an invite that quietly picked a role would be
   * granting authority nobody chose.
   */
  merchant_role_id: z.number().int(),
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
  merchant_role_id: z.number().int().optional(),
  is_active: z.boolean().optional(),
});
export type UpdateMerchantStaffRequest = z.infer<
  typeof UpdateMerchantStaffRequestSchema
>;

/** GET /api/merchant/staff — every panel account, id order. */
export function listMerchantStaff(
  options: RequestOptions = {},
): Promise<MerchantStaffListResponse> {
  return apiFetch("/api/merchant/staff", MerchantStaffListResponseSchema, {
    signal: options.signal,
  });
}

/**
 * POST /api/merchant/staff — creates a staff account (201) + one-time temp
 * password. `staff.invite` plus an approved store. Handing out a role the
 * caller could not hand out is refused with a MerchantRoleErrorCode
 * (`permission_not_held`, `owner_role_not_delegable`).
 */
export function createMerchantStaff(
  body: CreateMerchantStaffRequest,
  options: RequestOptions = {},
): Promise<CreateMerchantStaffResponse> {
  return apiFetch("/api/merchant/staff", CreateMerchantStaffResponseSchema, {
    method: "POST",
    body,
    signal: options.signal,
  });
}

/**
 * PATCH /api/merchant/staff/{id} — role and/or activation. There is
 * deliberately no DELETE: deactivation is the only removal. Moving off — or
 * deactivating — the merchant's last active OWNER-flagged account answers
 * 422, and the guard keys on that flag rather than on any permission (D4):
 * a custom role holding `staff.manage` must not become a way to leave the
 * store with nobody who can reach its bank account.
 */
export function updateMerchantStaff(
  id: number,
  body: UpdateMerchantStaffRequest,
  options: RequestOptions = {},
): Promise<MerchantStaffResponse> {
  return apiFetch(`/api/merchant/staff/${id}`, MerchantStaffResponseSchema, {
    method: "PATCH",
    body,
    signal: options.signal,
  });
}

// ---------------------------------------------------------------------------
// Settings — preferences (`preferences.update`)
// ---------------------------------------------------------------------------

/**
 * Operational preferences: how settlements are funded (§7), whether the
 * hourly run may settle from the wallet balance, and the two per-merchant
 * earning knobs. Both knobs apply to FUTURE credits only — terms freeze
 * onto each transaction at occurred_at (§4), so history never moves.
 */
export const MerchantPreferencesSchema = z.object({
  settlement_method: SettlementFundingMethodSchema,
  /**
   * Whether the hourly run settles validated cashback from the wallet
   * balance, oldest first, as much as fits (owner, 2026-08-24; default
   * ON). This PATCH is its ONE write path; the wallet payload
   * (`getMerchantWallet().data.auto_settle_from_wallet`) only reads it, so a
   * toggle rendered on the wallet screen writes here.
   */
  auto_settle_from_wallet: z.boolean(),
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
  /** The wallet screen's auto-settle toggle; see MerchantPreferencesSchema. */
  auto_settle_from_wallet: z.boolean().optional(),
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

/** PATCH /api/merchant/preferences — partial update; `preferences.update`. */
export function updateMerchantPreferences(
  body: UpdateMerchantPreferencesRequest,
  options: RequestOptions = {},
): Promise<MerchantPreferencesResponse> {
  return apiFetch(
    "/api/merchant/preferences",
    MerchantPreferencesResponseSchema,
    { method: "PATCH", body, signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// GET /api/merchant/customers/lookup — `customers.lookup`
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
export const CustomerLookupResponseSchema = z.discriminatedUnion("valid", [
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
// Settings — API access (`api_credentials.view` / `.create` / `.revoke`)
// ---------------------------------------------------------------------------

/**
 * The closed set of abilities a vendor token can carry (PLAN §9.1). Every
 * /v1 operation requires exactly one; a valid token lacking it answers 403.
 * Mirrors App\Domain\Credentials\VendorAbility — the server rejects anything
 * outside this list with a 422 on `abilities.*`.
 */
export const VENDOR_ABILITIES = [
  "transactions:write",
  "transactions:reverse",
  "rates:read",
  "customers:lookup",
  "webhooks:manage",
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
  /**
   * `https://shop.example.mv` for a grant a plugin made through
   * "Connect with Manfaa"; null otherwise. Lets two stores of one
   * merchant be told apart before one is revoked.
   */
  connected_from: z.string().nullable().optional().default(null),
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
    type: z.enum(["merchant_user", "admin", "unknown"]),
    name: z.string().nullable(),
  }),
  revoked_by_type: z.enum(["merchant_user", "admin", "unknown"]).nullable(),
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

// ---------------------------------------------------------------------------
// Merchant-owned webhook endpoints (owner, 2026-08-22)
// ---------------------------------------------------------------------------

/**
 * The events a merchant endpoint may subscribe to. `webhook.test` is NOT
 * here on purpose: it is what "Send test" delivers, and nothing else.
 */
export const WEBHOOK_EVENTS = [
  "merchant.rate_changed",
  "merchant.suspended",
  "merchant.reinstated",
  "transaction.reversed",
] as const;
export type WebhookEvent = (typeof WEBHOOK_EVENTS)[number];

export const MerchantWebhookEndpointSchema = z.object({
  id: z.number().int(),
  url: z.string(),
  label: z.string().nullable(),
  events: z.array(z.string()),
  active: z.boolean(),
  /**
   * Which door registered it. `credential` endpoints were set up by a token
   * over /v1 (a plugin) and are switched off when that token is revoked;
   * `panel` endpoints were typed in here and outlive every token.
   */
  registered_by: z.enum(["panel", "credential"]),
  api_credential_id: z.number().int().nullable(),
  /** The newest delivery of any event, for "last heard from". */
  last_delivery: z
    .object({
      event: z.string(),
      status: z.string(),
      response_status: z.number().int().nullable(),
      attempted_at: z.string().nullable(),
    })
    .nullable(),
  created_at: z.string().nullable(),
});
export type MerchantWebhookEndpoint = z.infer<typeof MerchantWebhookEndpointSchema>;

export const MerchantWebhookEndpointListResponseSchema = z.object({
  data: z.array(MerchantWebhookEndpointSchema),
});

export const CreateMerchantWebhookEndpointRequestSchema = z.object({
  url: z.string().url().max(2048),
  label: z.string().max(80).optional(),
  events: z.array(z.enum(WEBHOOK_EVENTS)).min(1),
});
export type CreateMerchantWebhookEndpointRequest = z.infer<
  typeof CreateMerchantWebhookEndpointRequestSchema
>;

export const CreateMerchantWebhookEndpointResponseSchema = z.object({
  /** Shown exactly once. The receiver verifies X-Manfaa-Signature with it. */
  secret: z.string(),
  endpoint: MerchantWebhookEndpointSchema,
});
export type CreateMerchantWebhookEndpointResponse = z.infer<
  typeof CreateMerchantWebhookEndpointResponseSchema
>;

/** GET /api/merchant/webhook-endpoints — newest first. `api_credentials.view`. */
export function listMerchantWebhookEndpoints(
  options: RequestOptions = {},
): Promise<z.infer<typeof MerchantWebhookEndpointListResponseSchema>> {
  return apiFetch(
    "/api/merchant/webhook-endpoints",
    MerchantWebhookEndpointListResponseSchema,
    { signal: options.signal },
  );
}

/**
 * POST /api/merchant/webhook-endpoints — registers one (201) and returns the
 * signing secret ONCE. `api_credentials.create`. Refusals by code:
 *
 *  - `store_not_approved` / `store_not_trading` (409) — as for credentials;
 *  - `endpoint_cap_reached` (422) — 5 active already; remove one;
 *  - a 422 validation error for a non-https or private-network URL.
 */
export function createMerchantWebhookEndpoint(
  body: CreateMerchantWebhookEndpointRequest,
  options: RequestOptions = {},
): Promise<CreateMerchantWebhookEndpointResponse> {
  return apiFetch(
    "/api/merchant/webhook-endpoints",
    CreateMerchantWebhookEndpointResponseSchema,
    { method: "POST", body, signal: options.signal },
  );
}

/** DELETE /api/merchant/webhook-endpoints/{id} — 204. `api_credentials.revoke`. */
export async function deleteMerchantWebhookEndpoint(
  id: number,
  options: RequestOptions = {},
): Promise<void> {
  await apiFetch(
    `/api/merchant/webhook-endpoints/${id}`,
    z.undefined(),
    { method: "DELETE", signal: options.signal },
  );
}

export const WebhookTestResponseSchema = z.object({
  delivery: z.object({
    id: z.number().int(),
    event: z.string(),
    status: z.string(),
  }),
});

/**
 * POST /api/merchant/webhook-endpoints/{id}/test — queues one `webhook.test`
 * delivery, signed exactly like a real event (202). `test_rate_limited`
 * (429) after 6 in a minute.
 */
export function testMerchantWebhookEndpoint(
  id: number,
  options: RequestOptions = {},
): Promise<z.infer<typeof WebhookTestResponseSchema>> {
  return apiFetch(
    `/api/merchant/webhook-endpoints/${id}/test`,
    WebhookTestResponseSchema,
    { method: "POST", signal: options.signal },
  );
}

/** GET /api/merchant/credentials — newest first, revoked rows included. */
export function listMerchantCredentials(
  options: RequestOptions = {},
): Promise<MerchantCredentialListResponse> {
  return apiFetch(
    "/api/merchant/credentials",
    MerchantCredentialListResponseSchema,
    { signal: options.signal },
  );
}

/**
 * POST /api/merchant/credentials — mints a vendor token (201) plus the
 * one-time plaintext. `api_credentials.create`. Refusals worth handling by
 * code:
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
    "/api/merchant/credentials",
    CreateMerchantCredentialResponseSchema,
    { method: "POST", body, signal: options.signal },
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
    { method: "DELETE", signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// MARKETPLACE — the merchant's own half (PLAN-marketplace.md §4, §9)
// ---------------------------------------------------------------------------

export const MarketplaceEnrolmentSchema = z.object({
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
  /** What this store is charged per order — its own rate, or the default. */
  order_fee_percent: z.string(),
  required_documents: z.array(z.string()),
  missing_documents: z.array(z.string()),
  documents: z.array(
    z.object({
      id: z.number().int(),
      kind: z.string(),
      original_name: z.string(),
      size: z.number().int(),
      state: z.string(),
      reject_reason: z.string().nullable(),
      uploaded_at: z.string().nullable(),
    }),
  ),
});
export type MarketplaceEnrolment = z.infer<typeof MarketplaceEnrolmentSchema>;

export function getMarketplaceEnrolment(options: RequestOptions = {}) {
  return apiFetch(
    "/api/merchant/marketplace/enrolment",
    dataWrapped(MarketplaceEnrolmentSchema),
    { signal: options.signal },
  );
}

export function enrolInMarketplace(body: {
  business_type: string;
  fulfilment: string;
  prep_time_min?: number | null;
  prep_time_max?: number | null;
}) {
  return apiFetch(
    "/api/merchant/marketplace/enrolment",
    dataWrapped(z.object({ state: z.string() })),
    { method: "POST", body },
  );
}

export function submitMarketplaceApplication() {
  return apiFetch(
    "/api/merchant/marketplace/submit",
    dataWrapped(z.object({ state: z.string() })),
    { method: "POST", body: {} },
  );
}

// -------------------------------------------------------------- catalogue

export const MarketplaceProductSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  name_dv: z.string().nullable(),
  description: z.string().nullable(),
  sku: z.string().nullable(),
  /**
   * Where it sits on the shelf — the SHOPPER's vocabulary, shared by every
   * store so cross-shop browse can work.
   */
  marketplace_category: z
    .object({
      id: z.number().int(),
      slug: z.string(),
      name_en: z.string(),
      name_dv: z.string().nullable(),
    })
    .nullable(),
  /**
   * What it EARNS, by this merchant's own pricing list. Optional: null is
   * the default "everything else" bucket at the standing rate, exactly as an
   * unfiled product behaves in-store.
   */
  cashback_category: z
    .object({
      id: z.number().int(),
      slug: z.string(),
      name_en: z.string(),
      name_dv: z.string().nullable(),
      mode: z.enum(["excluded", "rate"]),
      rate_percent: z.string().nullable(),
    })
    .nullable(),
  cashback_rate_percent: z.string().nullable(),
  allow_substitutions: z.boolean(),
  archived: z.boolean(),
  images: z.array(
    z.object({ id: z.number().int(), url: z.string(), sort: z.number().int() }),
  ),
  /** One per branch that stocks it — price and stock are per SHOP. */
  listings: z.array(
    z.object({
      id: z.number().int(),
      branch_id: z.number().int(),
      price_laari: z.number().int(),
      compare_at_laari: z.number().int().nullable(),
      stock_qty: z.number().int().nullable(),
      low_stock_at: z.number().int().nullable(),
      state: z.string(),
      buyable: z.boolean(),
      low_stock: z.boolean(),
    }),
  ),
});
export type MarketplaceProduct = z.infer<typeof MarketplaceProductSchema>;

export function listMarketplaceProducts(options: RequestOptions = {}) {
  return apiFetch(
    "/api/merchant/marketplace/products",
    z.object({
      data: z.array(MarketplaceProductSchema),
      meta: z.object({ pending_changes: z.array(z.unknown()).catch([]) }),
    }),
    { signal: options.signal },
  );
}

/** The shopper-facing aisle a product is filed under. */
export const MarketplaceAisleSchema = z.object({
  id: z.number().int(),
  slug: z.string(),
  name_en: z.string(),
  name_dv: z.string().nullable(),
  icon: z.string().nullable(),
});
export type MarketplaceAisle = z.infer<typeof MarketplaceAisleSchema>;

/** One of the merchant's OWN cashback categories, with what it pays. */
export const CashbackCategoryOptionSchema = z.object({
  id: z.number().int(),
  slug: z.string(),
  name_en: z.string(),
  name_dv: z.string().nullable(),
  mode: z.enum(["excluded", "rate"]),
  rate_percent: z.string().nullable(),
});
export type CashbackCategoryOption = z.infer<
  typeof CashbackCategoryOptionSchema
>;

/**
 * BOTH lists a product form needs, in one call.
 *
 * They are deliberately separate things: `marketplace` is the shared shelf
 * vocabulary every store draws from, `cashback` is this merchant's private
 * pricing. Merging them would splinter cross-store browse and leak one
 * shop's rate structure to shoppers.
 */
export function listMarketplaceCategories(options: RequestOptions = {}) {
  return apiFetch(
    "/api/merchant/marketplace/categories",
    dataWrapped(
      z.object({
        marketplace: z.array(MarketplaceAisleSchema),
        cashback: z.array(CashbackCategoryOptionSchema),
      }),
    ),
    { signal: options.signal },
  );
}

/** A product edit answers 202 with a change request on a LIVE listing. */
const ProductWriteSchema = z.union([
  z.object({ data: z.object({ change_request: z.unknown() }) }),
  dataWrapped(MarketplaceProductSchema),
]);

export function createMarketplaceProduct(body: Record<string, unknown>) {
  return apiFetch("/api/merchant/marketplace/products", ProductWriteSchema, {
    method: "POST",
    body,
  });
}

export function updateMarketplaceProduct(
  id: number,
  body: Record<string, unknown>,
) {
  return apiFetch(
    `/api/merchant/marketplace/products/${id}`,
    ProductWriteSchema,
    {
      method: "PATCH",
      body,
    },
  );
}

export function archiveMarketplaceProduct(id: number) {
  return apiFetch(
    `/api/merchant/marketplace/products/${id}`,
    dataWrapped(MarketplaceProductSchema),
    { method: "DELETE" },
  );
}

/** Price, stock and availability for ONE shop. Instant — never reviewed. */
export function setProductListing(
  productId: number,
  body: {
    branch_id: number;
    price_laari: number;
    compare_at_laari?: number | null;
    stock_qty?: number | null;
    low_stock_at?: number | null;
    state: string;
  },
) {
  return apiFetch(
    `/api/merchant/marketplace/products/${productId}/listing`,
    dataWrapped(z.object({ id: z.number().int(), state: z.string() })),
    { method: "PUT", body },
  );
}

// --------------------------------------------------------------- orders

export const MerchantOrderSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  state: z.string(),
  fulfilment: z.string(),
  pickup_code: z.string().nullable(),
  reject_reason: z.string().nullable(),
  placed_at: z.string().nullable(),
  payment_state: z.string().nullable(),
  customer: z.object({
    name: z.string().nullable(),
    phone: z.string().nullable(),
  }),
  address: z.record(z.string(), z.unknown()).nullable(),
  branch_name: z.string().nullable(),
  items_laari: z.number().int(),
  delivery_laari: z.number().int(),
  subtotal_laari: z.number().int(),
  cashback_laari: z.number().int(),
  order_fee_laari: z.number().int(),
  payable_to_merchant_laari: z.number().int(),
  items: z.array(
    z.object({
      id: z.number().int(),
      name: z.string(),
      /** What was ORDERED — immutable. */
      qty: z.number().int(),
      /** What this shop will supply. The gap is the amendment. */
      fulfilled_qty: z.number().int(),
      amended: z.boolean(),
      refund_laari: z.number().int(),
      unit_price_laari: z.number().int(),
      line_total_laari: z.number().int(),
    }),
  ),
});
export type MerchantOrder = z.infer<typeof MerchantOrderSchema>;

export function listMerchantOrders(tab: string, options: RequestOptions = {}) {
  return apiFetch(
    `/api/merchant/marketplace/orders?tab=${encodeURIComponent(tab)}`,
    z.object({
      data: z.array(MerchantOrderSchema),
      meta: z.object({
        new_count: z.number().int().catch(0),
        awaiting_action_count: z.number().int().catch(0),
      }),
    }),
    { signal: options.signal },
  );
}

const OrderActionSchema = dataWrapped(MerchantOrderSchema);

export function acceptMerchantOrder(id: number) {
  return apiFetch(
    `/api/merchant/marketplace/orders/${id}/accept`,
    OrderActionSchema,
    {
      method: "POST",
      body: {},
    },
  );
}

export function rejectMerchantOrder(id: number, reason: string) {
  return apiFetch(
    `/api/merchant/marketplace/orders/${id}/reject`,
    OrderActionSchema,
    {
      method: "POST",
      body: { reason },
    },
  );
}

export function advanceMerchantOrder(id: number, state: string) {
  return apiFetch(
    `/api/merchant/marketplace/orders/${id}/advance`,
    OrderActionSchema,
    {
      method: "POST",
      body: { state },
    },
  );
}

/** Reduce what will be supplied. Only ever DOWN — see PLAN §2.7. */
export function amendMerchantOrder(
  id: number,
  lines: { suborder_item_id: number; fulfilled_qty: number }[],
  reason: string,
  note?: string,
) {
  return apiFetch(
    `/api/merchant/marketplace/orders/${id}/amend`,
    OrderActionSchema,
    {
      method: "POST",
      body: { lines, reason, note },
    },
  );
}

// ------------------------------------------------------------- delivery

export const BranchDeliveryRowSchema = z.object({
  zone_id: z.number().int(),
  zone_name: z.string(),
  zone_name_dv: z.string().nullable(),
  /** The absence of a rule IS the answer: we do not deliver there. */
  delivers: z.boolean(),
  free_delivery_over_laari: z.number().int().nullable(),
  delivery_fee_laari: z.number().int().nullable(),
  order_minimum_laari: z.number().int().nullable(),
  eta_min: z.number().int().nullable(),
  eta_max: z.number().int().nullable(),
});
export type BranchDeliveryRow = z.infer<typeof BranchDeliveryRowSchema>;

export function getBranchDelivery(
  branchId: number,
  options: RequestOptions = {},
) {
  return apiFetch(
    `/api/merchant/marketplace/branches/${branchId}/delivery`,
    z.object({ data: z.array(BranchDeliveryRowSchema) }),
    { signal: options.signal },
  );
}

export function setBranchDelivery(
  branchId: number,
  body: {
    zone_id: number;
    delivery_fee_laari: number;
    free_delivery_over_laari?: number | null;
    order_minimum_laari?: number | null;
    eta_min?: number | null;
    eta_max?: number | null;
  },
) {
  return apiFetch(
    `/api/merchant/marketplace/branches/${branchId}/delivery`,
    dataWrapped(z.unknown()),
    { method: "PUT", body },
  );
}

export function removeBranchDelivery(branchId: number, zoneId: number) {
  return apiFetch(
    `/api/merchant/marketplace/branches/${branchId}/delivery/${zoneId}`,
    z.unknown(),
    { method: "DELETE" },
  );
}

// ---------------------------------------------------------------------------
// Connect — "IsleBooks would like to … Authorise / Deny"
// ---------------------------------------------------------------------------

/**
 * The consent screen a platform sends the shopkeeper to. The panel reads
 * the query string the platform put in the URL, asks the server what it
 * means, and draws the question. Reading writes nothing: opening the screen
 * and closing it again grants nothing.
 */
export const ConnectConsentQuerySchema = z.object({
  client_id: z.string(),
  redirect_uri: z.string(),
  /** Space- or comma-separated abilities. */
  scope: z.string(),
});
export type ConnectConsentQuery = z.infer<typeof ConnectConsentQuerySchema>;

export const ConnectPermissionSchema = z.object({
  ability: z.string(),
  /** The sentence the shopkeeper reads, in the order asked. */
  line: z.string(),
  caution: z.string().nullable(),
});
export type ConnectPermission = z.infer<typeof ConnectPermissionSchema>;

export const ConnectConsentSchema = z.object({
  application: z.object({
    name: z.string(),
    description: z.string().nullable(),
    website: z.string().nullable(),
    /** A plugin on the merchant's own server (the WooCommerce plugin). */
    public_client: z.boolean().optional().default(false),
  }),
  /**
   * For a public client, the host of the callback the plugin sent —
   * "This will connect shop.example.mv". Null for a confidential platform,
   * whose callbacks a superadmin registered.
   */
  callback_host: z.string().nullable().optional().default(null),
  store: z.object({ name: z.string() }),
  permissions: z.array(ConnectPermissionSchema),
  /** Authorising again REPLACES the existing grant — worth saying before. */
  already_connected: z.boolean(),
  /**
   * Non-null when Authorise cannot succeed — today, only a store already
   * holding its maximum live credentials. The string says what to do about
   * it. Asked here rather than discovered at exchange time, when the
   * merchant would already believe they had connected.
   */
  blocked_reason: z.string().nullable(),
});
export type ConnectConsent = z.infer<typeof ConnectConsentSchema>;

export const ConnectConsentResponseSchema = dataWrapped(ConnectConsentSchema);
export type ConnectConsentResponse = z.infer<
  typeof ConnectConsentResponseSchema
>;

/** Both answers hand back where to send the browser next. */
export const ConnectRedirectResponseSchema = dataWrapped(
  z.object({ redirect_to: z.string() }),
);
export type ConnectRedirectResponse = z.infer<
  typeof ConnectRedirectResponseSchema
>;

/**
 * GET /api/merchant/connect/authorize — what the consent screen shows.
 * 422 (with a `code`) when the platform is unknown, not enabled, asking for
 * more than a superadmin allowed it, or naming an unregistered callback.
 */
export function readConnectConsent(
  query: ConnectConsentQuery,
  options: { signal?: AbortSignal } = {},
): Promise<ConnectConsentResponse> {
  const params = new URLSearchParams(query as Record<string, string>);

  return apiFetch(
    `/api/merchant/connect/authorize?${params.toString()}`,
    ConnectConsentResponseSchema,
    { signal: options.signal },
  );
}

export const ApproveConnectRequestSchema = ConnectConsentQuerySchema.extend({
  /** Echoed back untouched so the platform can match its own request. */
  state: z.string().nullish(),
  /** PKCE: the platform's challenge, minted by the platform, not by us. */
  code_challenge: z.string().min(43).max(128),
  code_challenge_method: z.literal("S256"),
});
export type ApproveConnectRequest = z.infer<typeof ApproveConnectRequestSchema>;

/**
 * POST /api/merchant/connect/authorize — mints the one-time code the
 * platform will exchange, server to server. Needs `api_credentials.create`:
 * pressing Authorise IS issuing a key, just without anybody seeing it.
 */
export function approveConnect(
  body: ApproveConnectRequest,
): Promise<ConnectRedirectResponse> {
  return apiFetch(
    "/api/merchant/connect/authorize",
    ConnectRedirectResponseSchema,
    { method: "POST", body },
  );
}

/**
 * POST /api/merchant/connect/deny — no code is minted; the platform is told
 * plainly, because one left waiting cannot tell refusal from a shopkeeper
 * who wandered off, and will keep asking.
 */
export function denyConnect(body: {
  client_id: string;
  redirect_uri: string;
  state?: string | null;
}): Promise<ConnectRedirectResponse> {
  return apiFetch("/api/merchant/connect/deny", ConnectRedirectResponseSchema, {
    method: "POST",
    body,
  });
}

// ---------------------------------------------------------------------------
// POS-fee waiver (owner, 2026-08-23): free IsleBooks for qualifying months
// ---------------------------------------------------------------------------

export const PosWaiverMonthSchema = z.object({
  month: z.string(),
  qualified: z.boolean(),
  volume_laari: z.number().int(),
  cashback_laari: z.number().int(),
  min_rate_bp: z.number().int(),
  overdue_laari: z.number().int(),
});

export const PosWaiverSchema = z.object({
  criteria: z.object({
    min_rate_bp: z.number().int(),
    volume_threshold_laari: z.number().int(),
    cashback_threshold_laari: z.number().int(),
  }),
  last_month: PosWaiverMonthSchema.nullable(),
  current_month: z.object({
    month: z.string(),
    volume_laari: z.number().int(),
    cashback_laari: z.number().int(),
    min_rate_bp: z.number().int(),
    overdue_laari: z.number().int(),
    rate_ok: z.boolean(),
    overdue_ok: z.boolean(),
  }),
});
export type PosWaiver = z.infer<typeof PosWaiverSchema>;

/** GET /api/merchant/pos-waiver — settlements.view. */
export function getPosWaiver(
  options: RequestOptions = {},
): Promise<{ data: PosWaiver }> {
  return apiFetch(
    "/api/merchant/pos-waiver",
    z.object({ data: PosWaiverSchema }),
    { signal: options.signal },
  );
}
