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

export const TransactionOriginSchema = z.enum([
  'pos',
  'manual',
  'online_link',
  'api_phone',
  'card_linked',
]);
export type TransactionOrigin = z.infer<typeof TransactionOriginSchema>;

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
  occurred_at: z.string(),
  received_at: z.string(),
});
export type Transaction = z.infer<typeof TransactionSchema>;

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
  slip_path: z.string().nullable(),
  state: SettlementPaymentStateSchema,
  matched_by: z.number().int().nullable(),
  matched_at: z.string().nullable(),
  created_at: z.string(),
});
export type SettlementPayment = z.infer<typeof SettlementPaymentSchema>;

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
  bank_account: z
    .object({
      bank_name: z.string(),
      account_no: z.string(),
      account_name: z.string(),
    })
    .nullable(),
  needs_configuration: z.boolean(),
});
export type SettlementPaymentInstructions = z.infer<
  typeof SettlementPaymentInstructionsSchema
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
  // Present only on endpoints that eager-load the relations.
  lines: z.array(SettlementLineSchema).optional(),
  payments: z.array(SettlementPaymentSchema).optional(),
});
export type Settlement = z.infer<typeof SettlementSchema>;

// ---------------------------------------------------------------------------
// Merchant wallet
// ---------------------------------------------------------------------------

export const WalletMovementSchema = z.object({
  id: z.number().int(),
  amount_laari: z.number().int(),
  amount_mvr: z.string(),
  balance_after_laari: z.number().int(),
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
