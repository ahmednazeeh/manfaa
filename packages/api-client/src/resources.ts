import { z } from 'zod';

/**
 * Zod schemas mirroring the Laravel JsonResources shared by the merchant and
 * admin surfaces. Money is always integer laari (`z.number().int()`); the
 * `*_mvr` companions are pre-formatted decimal strings from the API (e.g.
 * "118.25") and are display-only — never parse them back into numbers.
 */

// ---------------------------------------------------------------------------
// Response envelopes
// ---------------------------------------------------------------------------

/** Laravel wraps a single JsonResource response as `{ data: ... }`. */
export function dataWrapped<Schema extends z.ZodType>(schema: Schema) {
  return z.object({ data: schema });
}

export const PaginationLinksSchema = z.object({
  first: z.string().nullable(),
  last: z.string().nullable(),
  prev: z.string().nullable(),
  next: z.string().nullable(),
});
export type PaginationLinks = z.infer<typeof PaginationLinksSchema>;

export const PaginationMetaSchema = z.object({
  current_page: z.number().int(),
  from: z.number().int().nullable(),
  last_page: z.number().int(),
  links: z.array(
    z.object({
      url: z.string().nullable(),
      label: z.string(),
      // Absent on the "..." separator entries.
      page: z.number().int().nullable().optional(),
      active: z.boolean(),
    }),
  ),
  path: z.string().nullable(),
  per_page: z.number().int(),
  to: z.number().int().nullable(),
  total: z.number().int(),
});
export type PaginationMeta = z.infer<typeof PaginationMetaSchema>;

/** Laravel resource-collection pagination: `{ data, links, meta }`. */
export function paginated<Schema extends z.ZodType>(schema: Schema) {
  return z.object({
    data: z.array(schema),
    links: PaginationLinksSchema,
    meta: PaginationMetaSchema,
  });
}

// ---------------------------------------------------------------------------
// Merchant lifecycle + channel (§1 decisions 2026-08-15)
// ---------------------------------------------------------------------------

/**
 * Where the store sells. Replaces the former `is_online` boolean everywhere.
 * Display copy NEVER says "both" — the UI renders it as "In Store & Online"
 * (localised).
 */
export const MerchantChannelSchema = z.enum(['in_store', 'online', 'both']);
export type MerchantChannel = z.infer<typeof MerchantChannelSchema>;

/**
 * The full merchant lifecycle. `draft` (mid-wizard), `pending_review`
 * (submitted, awaiting the superadmin queue) and `rejected` (sent back with
 * a reason) are the self-signup onboarding states — none of the three is
 * EVER visible publicly; public payloads expose ACTIVE merchants only.
 */
export const MerchantStatusSchema = z.enum([
  'draft',
  'pending_review',
  'rejected',
  'active',
  'suspended',
  'closed',
]);
export type MerchantStatus = z.infer<typeof MerchantStatusSchema>;

// ---------------------------------------------------------------------------
// Transactions
// ---------------------------------------------------------------------------

export const TransactionStateSchema = z.enum([
  'tracked',
  'awaiting_validation',
  'payable_unfunded',
  'on_hold',
  'confirmed',
  'paid',
  'reversed',
  'written_off',
]);
export type TransactionState = z.infer<typeof TransactionStateSchema>;

/**
 * How the sale reached us. `claim` is written by ClaimApprovalService when an
 * admin approves a missing-transaction claim — it is a real value of the
 * transactions.origin CHECK constraint, so it must live here or a single
 * claim-originated row would fail the response parse and blank the screen.
 */
export const TransactionOriginSchema = z.enum([
  'pos',
  'manual',
  'online_link',
  'api_phone',
  'card_linked',
  'claim',
]);
export type TransactionOrigin = z.infer<typeof TransactionOriginSchema>;

/**
 * Every `reason_code` the API writes onto a transaction row or one of its
 * append-only events — the state QUALIFIER that answers "why is this row
 * where it is". Codes are machine keys; no UI may render one raw (PLAN §13b
 * task #22), so each app keeps an EXHAUSTIVE label map typed against this
 * union and a new entry here fails every app's typecheck until it is
 * labelled.
 *
 * Where each one is written:
 *
 *   auto_validation_window  CreditRecorder — clean credit enters the refund
 *                           window (event only; the row's reason stays null)
 *   backdated_final         CreditRecorder — PLAN §1 backdated credit: never
 *                           sat in the refund window, payable now, merchant-
 *                           irreversible (row + event)
 *   below_minimum           CreditRecorder — under the store's minimum
 *                           eligible sale; recorded, zeroed, closed
 *   merchant_suspended      ApiCreditService — §7 suspended store; ingested
 *                           and recorded ineligible so the till sees truth
 *   settlement_allocated    LineAllocator — the store's payment covered this
 *                           line, so the reward confirmed
 *   payout_completed        ResultImporter — the bank confirmed the customer
 *                           transfer
 *   merchant_default_90d    WriteOffService — 90 days past due, never settled
 *   claim_approved          ClaimApprovalService — created from an approved
 *                           missing-transaction claim
 *   customer_refund         reversal reason (POS /v1 + admin adjustment)
 *   till_void               reversal reason
 *   duplicate               reversal reason
 *   other                   reversal reason
 *   admin_release           hold queue — a human cleared the review
 *   admin_reject            hold queue — a human refused the sale
 *
 * LEGACY — no code writes these any more (task #23 removed the staleness
 * hold), but production rows and event history still carry them, so the
 * labels stay:
 *
 *   stale_timestamp         the old staleness hold
 *   admin_release_stale     an admin releasing one of those holds
 */
export const TRANSACTION_REASON_CODES = [
  'auto_validation_window',
  'backdated_final',
  'below_minimum',
  'merchant_suspended',
  'settlement_allocated',
  'payout_completed',
  'merchant_default_90d',
  'claim_approved',
  'customer_refund',
  'till_void',
  'duplicate',
  'other',
  'admin_release',
  'admin_reject',
  'stale_timestamp',
  'admin_release_stale',
] as const;
export const TransactionReasonCodeSchema = z.enum(TRANSACTION_REASON_CODES);
export type TransactionReasonCode = (typeof TRANSACTION_REASON_CODES)[number];

/**
 * Narrows an arbitrary `reason_code` from the wire. The column is a plain
 * string on purpose — an older row may carry a code this build predates — so
 * label helpers test with this and fall back to prose, never to the code.
 */
export function isTransactionReasonCode(
  value: string,
): value is TransactionReasonCode {
  return (TRANSACTION_REASON_CODES as readonly string[]).includes(value);
}

// ---------------------------------------------------------------------------
// Product categories + line-item pricing (Task #25)
// ---------------------------------------------------------------------------

/**
 * What a per-store product category does to the rate: `excluded` earns
 * nothing (even during promotions), `rate` overrides the standing rate with
 * the category's own `rate_bp`.
 */
export const ProductCategoryModeSchema = z.enum(['excluded', 'rate']);
export type ProductCategoryMode = z.infer<typeof ProductCategoryModeSchema>;

/**
 * WHY a line priced the way it did: `excluded` (category excluded → zeros),
 * `category` (the category's own rate override), `standing` (no category /
 * default bucket → merchant standing rate), `promotion` (a live promo beat
 * the line's own rate — only these lines consume the per-customer promo cap).
 */
export const TransactionLinePricedBySchema = z.enum([
  'excluded',
  'category',
  'standing',
  'promotion',
]);
export type TransactionLinePricedBy = z.infer<
  typeof TransactionLinePricedBySchema
>;

/**
 * One priced line of a lined credit — an immutable creation-time snapshot.
 * `category` is the product-category slug (null = the default "everything
 * else" bucket, which also has a null `category_name_en`). All money is
 * integer laari, per-line §4 ceiling; the transaction totals equal the SUM
 * of these stored integers.
 */
export const TransactionLineSchema = z.object({
  category: z.string().nullable(),
  category_name_en: z.string().nullable(),
  amount_laari: z.number().int(),
  effective_rate_bp: z.number().int(),
  fee_bp: z.number().int(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  priced_by: TransactionLinePricedBySchema,
  sort: z.number().int(),
});
export type TransactionLine = z.infer<typeof TransactionLineSchema>;

export const TransactionSchema = z.object({
  id: z.number().int(),
  origin: TransactionOriginSchema,
  invoice_no: z.string(),
  state: TransactionStateSchema,
  reason_code: z.string().nullable(),
  currency: z.string(),
  eligible_laari: z.number().int(),
  sale_laari: z.number().int().nullable(),
  rate_bp: z.number().int(),
  fee_bp: z.number().int(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  /**
   * PLAN §1 "Backdated credits": the sale was credited outside the merchant's
   * validation window, so it never sat in the refund window — it is payable
   * immediately AND permanently merchant-irreversible (an admin adjustment is
   * the only correction). Branch on this flag, never on `reason_code`, which
   * later transitions rewrite.
   */
  backdated: z.boolean(),
  occurred_at: z.string(),
  received_at: z.string(),
  /**
   * The pricing split of a lined credit, in submitted order. Present only
   * when the endpoint loads it (e.g. the credit POST response for a lined
   * credit); single-rate transactions keep the exact pre-lines shape. On a
   * lined credit the row-level rate_bp/fee_bp are the standing-rate
   * snapshot — the per-line truth lives here.
   */
  lines: z.array(TransactionLineSchema).optional(),
});
export type Transaction = z.infer<typeof TransactionSchema>;

// ---------------------------------------------------------------------------
// Backdated credits (PLAN §1, decision 2026-08-14 late)
// ---------------------------------------------------------------------------

/**
 * The state qualifier a backdated credit carries through its (instantaneous)
 * hop to payable_unfunded. It is an EVENT reason, not a row state: it tells
 * a history reader why the credit skipped the validation window. No admin
 * approval is involved — on_hold is now fraud/velocity only.
 */
export const BACKDATED_REASON_CODE = 'backdated_final';

/**
 * The 409 `code` a reversal of a backdated credit answers with — merchant
 * and vendor alike (POST /v1/transactions/{id}/reverse). Distinct from a
 * plain failure so a POS can tell the cashier the truth: this one needs an
 * admin adjustment, retrying will never work.
 */
export const BACKDATED_IRREVERSIBLE_CODE = 'backdated_irreversible';

/**
 * Days past the merchant's validation window before the API treats a credit
 * as backdated (CreditRecorder::STALE_GRACE_DAYS). Kept in step with the
 * server so the entry form's warning fires on exactly the sales the server
 * will make final.
 */
export const BACKDATED_STALE_GRACE_DAYS = 3;

/**
 * Would this sale be credited as BACKDATED — payable immediately and
 * merchant-irreversible? Mirrors the server rule exactly: occurred_at
 * strictly before now minus (validation window + grace days). The entry form
 * warns on true BEFORE submit, because the decision cannot be undone
 * afterwards.
 *
 * @param validationWindowDays the merchant's own preferences value
 */
export function isBackdatedOccurrence(
  occurredAt: string | number | Date,
  validationWindowDays: number,
  now: Date = new Date(),
): boolean {
  const occurred =
    occurredAt instanceof Date
      ? occurredAt.getTime()
      : typeof occurredAt === 'number'
        ? occurredAt
        : Date.parse(occurredAt);

  if (!Number.isFinite(occurred)) {
    return false;
  }

  const staleAfterMs =
    (validationWindowDays + BACKDATED_STALE_GRACE_DAYS) * 24 * 60 * 60 * 1000;

  return now.getTime() - occurred > staleAfterMs;
}

// ---------------------------------------------------------------------------
// Promotions (shared by the merchant builder and the admin listing)
// ---------------------------------------------------------------------------

export const PromotionStatusSchema = z.enum([
  'draft',
  'published',
  'ended',
  'cancelled',
]);
export type PromotionStatus = z.infer<typeof PromotionStatusSchema>;

/**
 * The §4 cost picture of one cashback rate, all integer basis points: the
 * platform fee tier the rate lands on and the resulting all-in merchant
 * cost. Mind the tier cliffs (e.g. 499 → 500 moves the fee tier).
 */
export const RateDescriptionSchema = z.object({
  rate_bp: z.number().int(),
  fee_bp: z.number().int(),
  all_in_bp: z.number().int(),
});
export type RateDescription = z.infer<typeof RateDescriptionSchema>;

/**
 * One promotion as the merchant and admin panels see it. The fee fields are
 * resolved from the promo rate's §4 tier exactly as they will be at credit
 * time. Timestamps are ISO 8601 in the business timezone (UTC+5).
 *
 * fee_bp/all_in_bp are null in exactly one degenerate case: a stale DRAFT
 * whose rate the fee schedule now governing its window no longer prices
 * (drafts never block an admin schedule change; publish would refuse this
 * draft). The listing must still render so the merchant can see and cancel
 * it.
 */
export const PromotionSchema = RateDescriptionSchema.extend({
  fee_bp: z.number().int().nullable(),
  all_in_bp: z.number().int().nullable(),
  id: z.number().int(),
  merchant_id: z.number().int(),
  branch_id: z.number().int().nullable(),
  status: PromotionStatusSchema,
  /** published AND the window covers now. */
  is_live: z.boolean(),
  starts_at: z.string(),
  ends_at: z.string(),
  min_purchase_laari: z.number().int().nullable(),
  max_cashback_per_customer_laari: z.number().int().nullable(),
  published_at: z.string().nullable(),
  cancelled_at: z.string().nullable(),
});
export type Promotion = z.infer<typeof PromotionSchema>;

// ---------------------------------------------------------------------------
// Claims (customer files them; admin resolves them)
// ---------------------------------------------------------------------------

export const ClaimStateSchema = z.enum([
  'open',
  'in_review',
  'approved',
  'rejected',
]);
export type ClaimState = z.infer<typeof ClaimStateSchema>;

// ---------------------------------------------------------------------------
// Settlements
// ---------------------------------------------------------------------------

export const SettlementStateSchema = z.enum([
  'draft',
  'awaiting_payment',
  'payment_review',
  'settled',
  'partially_settled',
  'cancelled',
]);
export type SettlementState = z.infer<typeof SettlementStateSchema>;

export const SettlementFundingMethodSchema = z.enum(['bank', 'wallet']);
export type SettlementFundingMethod = z.infer<
  typeof SettlementFundingMethodSchema
>;

export const SettlementLineSchema = z.object({
  id: z.number().int(),
  transaction_id: z.number().int(),
  currency: z.string(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  due_laari: z.number().int(),
  due_mvr: z.string(),
  allocated_at: z.string().nullable(),
  // Present only when the endpoint eager-loads the transaction.
  transaction: TransactionSchema.optional(),
});
export type SettlementLine = z.infer<typeof SettlementLineSchema>;

export const SettlementPaymentStateSchema = z.enum([
  'pending',
  'matched',
  'rejected',
]);
export type SettlementPaymentState = z.infer<
  typeof SettlementPaymentStateSchema
>;

export const SettlementPaymentSchema = z.object({
  id: z.number().int(),
  settlement_id: z.number().int(),
  amount_laari: z.number().int(),
  amount_mvr: z.string(),
  currency: z.string(),
  method: z.string(),
  bank_ref: z.string().nullable(),
  /**
   * Path on the private `slips` disk. It is NOT fetchable — the disk has no
   * URL and is not served. Branch on `has_slip`; read the bytes only through
   * the authenticated admin slip route.
   */
  slip_path: z.string().nullable(),
  has_slip: z.boolean(),
  /** Mime derived from the uploaded BYTES, never the client's Content-Type. */
  slip_mime: z.string().nullable(),
  slip_size_bytes: z.number().int().nullable(),
  uploaded_by: z.number().int().nullable(),
  state: SettlementPaymentStateSchema,
  matched_by: z.number().int().nullable(),
  matched_at: z.string().nullable(),
  rejected_by: z.number().int().nullable(),
  rejected_at: z.string().nullable(),
  /** Why an admin refused the receipt — the merchant reads this verbatim. */
  rejection_reason: z.string().nullable(),
  created_at: z.string(),
});
export type SettlementPayment = z.infer<typeof SettlementPaymentSchema>;

/**
 * The platform's active primary account, exactly as the merchant must type
 * it into their bank. Copy buttons quote these three strings verbatim.
 */
export const SettlementBankAccountSchema = z.object({
  bank_name: z.string(),
  account_no: z.string(),
  account_name: z.string(),
});
export type SettlementBankAccount = z.infer<typeof SettlementBankAccountSchema>;

/**
 * Where to actually send the transfer: the platform's active primary bank
 * account alongside the amount and the reference to quote. When no platform
 * account is configured, `bank_account` is null and `needs_configuration`
 * is true — details are never invented.
 */
export const SettlementPaymentInstructionsSchema = z.object({
  /** The settlement reference the merchant must quote on the transfer. */
  reference: z.string(),
  amount_due_laari: z.number().int(),
  amount_due_mvr: z.string(),
  bank_account: SettlementBankAccountSchema.nullable(),
  needs_configuration: z.boolean(),
});
export type SettlementPaymentInstructions = z.infer<
  typeof SettlementPaymentInstructionsSchema
>;

/**
 * The rejection that cancelled a batch, read off the payment the admin
 * refused. Present only on a cancelled batch whose payments are loaded — a
 * plain cancellation (no payment ever recorded) refuses nothing and carries
 * no rejection.
 */
export const SettlementRejectionSchema = z.object({
  reason: z.string().nullable(),
  rejected_at: z.string().nullable(),
  /** The bank reference the merchant quoted on the refused transfer. */
  bank_ref: z.string().nullable(),
  payment_id: z.number().int(),
});
export type SettlementRejection = z.infer<typeof SettlementRejectionSchema>;

/**
 * What the MERCHANT is told about their batch (PLAN §1 receipt-first). The
 * raw §6 `state` stays for machines; this is the human answer to "what is
 * happening to my transfer" — `verifying` while an admin reviews the slip,
 * `rejected` (with the reason) when the transfer could not be verified.
 */
export const SettlementMerchantStatusCodeSchema = z.enum([
  'draft',
  'awaiting_payment',
  'verifying',
  'settled',
  'partially_settled',
  'rejected',
  'cancelled',
]);
export type SettlementMerchantStatusCode = z.infer<
  typeof SettlementMerchantStatusCodeSchema
>;

export const SettlementMerchantStatusSchema = z.object({
  code: SettlementMerchantStatusCodeSchema,
  /** English prose from the API; localise off `code`, not off this. */
  message: z.string(),
  rejection: SettlementRejectionSchema.nullable(),
});
export type SettlementMerchantStatus = z.infer<
  typeof SettlementMerchantStatusSchema
>;

export const SettlementSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  state: SettlementStateSchema,
  funding_method: SettlementFundingMethodSchema,
  currency: z.string(),
  sale_total_laari: z.number().int(),
  cashback_total_laari: z.number().int(),
  fee_total_laari: z.number().int(),
  fee_gst_total_laari: z.number().int(),
  amount_due_laari: z.number().int(),
  amount_received_laari: z.number().int(),
  cashback_total_mvr: z.string(),
  fee_total_mvr: z.string(),
  amount_due_mvr: z.string(),
  amount_received_mvr: z.string(),
  due_at: z.string().nullable(),
  created_at: z.string(),
  payment_instructions: SettlementPaymentInstructionsSchema,
  /**
   * The merchant-facing reading of `state` (PLAN §1). `rejection` is filled
   * only when the endpoint loads the payments — the merchant list and detail
   * both do, so a rejected batch always explains itself.
   */
  merchant_status: SettlementMerchantStatusSchema,
  // Present only on endpoints that eager-load the relations.
  lines: z.array(SettlementLineSchema).optional(),
  payments: z.array(SettlementPaymentSchema).optional(),
});
export type Settlement = z.infer<typeof SettlementSchema>;

// ---------------------------------------------------------------------------
// Merchant wallet
// ---------------------------------------------------------------------------

/**
 * What moved the merchant wallet: `top_up` (bank transfer in), `settlement`
 * (wallet balance spent on a batch) and `settlement_credit` (an overpayment
 * or unallocated remainder parked for the next batch) — WalletFunding and
 * SettlementAllocator write no others.
 */
export const WALLET_MOVEMENT_TYPES = [
  'top_up',
  'settlement',
  'settlement_credit',
] as const;
export type WalletMovementType = (typeof WALLET_MOVEMENT_TYPES)[number];

export function isWalletMovementType(
  value: string,
): value is WalletMovementType {
  return (WALLET_MOVEMENT_TYPES as readonly string[]).includes(value);
}

export const WalletMovementSchema = z.object({
  id: z.number().int(),
  amount_laari: z.number().int(),
  amount_mvr: z.string(),
  balance_after_laari: z.number().int(),
  /** Kept a free string on the wire; narrow with isWalletMovementType. */
  type: z.string(),
  reference_type: z.string().nullable(),
  reference_id: z.number().int().nullable(),
  description: z.string().nullable(),
  created_at: z.string(),
});
export type WalletMovement = z.infer<typeof WalletMovementSchema>;

export const WalletSchema = z.object({
  balance_laari: z.number().int(),
  balance_mvr: z.string(),
  currency: z.string(),
  transactions: z.array(WalletMovementSchema).optional(),
});
export type Wallet = z.infer<typeof WalletSchema>;

// ---------------------------------------------------------------------------
// Payout batches
// ---------------------------------------------------------------------------

export const PayoutBatchStateSchema = z.enum([
  'draft',
  'approved',
  'processing',
  'sent',
  'completed',
  'partially_failed',
  'cancelled',
]);
export type PayoutBatchState = z.infer<typeof PayoutBatchStateSchema>;

export const PayoutItemStateSchema = z.enum([
  'pending',
  'sent',
  'paid',
  'failed',
]);
export type PayoutItemState = z.infer<typeof PayoutItemStateSchema>;

export const PayoutItemSchema = z.object({
  id: z.number().int(),
  batch_id: z.number().int(),
  customer_id: z.number().int(),
  amount_laari: z.number().int(),
  currency: z.string(),
  bank: z.string().nullable(),
  account: z.string().nullable(),
  account_name: z.string().nullable(),
  state: PayoutItemStateSchema,
  failure_reason: z.string().nullable(),
  bank_reference: z.string().nullable(),
});
export type PayoutItem = z.infer<typeof PayoutItemSchema>;

export const PayoutBatchSchema = z.object({
  id: z.number().int(),
  reference: z.string(),
  state: PayoutBatchStateSchema,
  period_start: z.string(),
  period_end: z.string(),
  cutoff_at: z.string(),
  total_laari: z.number().int(),
  currency: z.string(),
  customer_count: z.number().int(),
  // Money waiting on bank details: eligible customers skipped at build time.
  excluded_customer_count: z.number().int(),
  excluded_total_laari: z.number().int(),
  created_by: z.number().int().nullable(),
  approved_by_first: z.number().int().nullable(),
  approved_by_second: z.number().int().nullable(),
  first_approved_at: z.string().nullable(),
  second_approved_at: z.string().nullable(),
  exported_at: z.string().nullable(),
  // Present only on endpoints that eager-load the items.
  items: z.array(PayoutItemSchema).optional(),
});
export type PayoutBatch = z.infer<typeof PayoutBatchSchema>;
