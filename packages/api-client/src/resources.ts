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
