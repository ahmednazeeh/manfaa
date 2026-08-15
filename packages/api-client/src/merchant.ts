import { z } from 'zod';
import { apiFetch } from './client';
import {
  dataWrapped,
  MerchantChannelSchema,
  MerchantStatusSchema,
  paginated,
  ProductCategoryModeSchema,
  PromotionSchema,
  PromotionStatusSchema,
  RateDescriptionSchema,
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

export const OutstandingSummarySchema = z.object({
  as_of: z.string(),
  total: OutstandingTotalSchema,
  buckets: z.object({
    '0_5': OutstandingBucketSchema,
    '6_10': OutstandingBucketSchema,
    '11_15': OutstandingBucketSchema,
    overdue: OutstandingBucketSchema,
  }),
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

export const CreateSettlementRequestSchema = z.union([
  z.object({ settle_all: z.literal(true) }),
  z.object({ ids: z.array(z.number().int()).min(1) }),
]);
export type CreateSettlementRequest = z.infer<
  typeof CreateSettlementRequestSchema
>;

/** POST /api/merchant/settlements — creates a draft (201) with lines loaded. */
export function createMerchantSettlement(
  body: CreateSettlementRequest,
  options: RequestOptions = {},
): Promise<MerchantSettlementResponse> {
  return apiFetch('/api/merchant/settlements', MerchantSettlementResponseSchema, {
    method: 'POST',
    body,
    signal: options.signal,
  });
}

/** POST /api/merchant/settlements/{id}/submit — draft -> awaiting_payment. */
export function submitMerchantSettlement(
  id: number,
  options: RequestOptions = {},
): Promise<MerchantSettlementResponse> {
  return apiFetch(
    `/api/merchant/settlements/${id}/submit`,
    MerchantSettlementResponseSchema,
    { method: 'POST', signal: options.signal },
  );
}

/** POST /api/merchant/settlements/{id}/wallet-settle — settle entirely from the wallet. */
export function walletSettleMerchantSettlement(
  id: number,
  options: RequestOptions = {},
): Promise<MerchantSettlementResponse> {
  return apiFetch(
    `/api/merchant/settlements/${id}/wallet-settle`,
    MerchantSettlementResponseSchema,
    { method: 'POST', signal: options.signal },
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
  /** ISO 8601 with an explicit UTC offset, e.g. "2026-08-14T10:30:00+05:00". */
  occurred_at: z.string(),
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

/** POST /api/merchant/credits — records a manual credit (201). */
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
 * touch it. Mode/rate changes reprice FUTURE credits only. `rate_bp` is set
 * exactly when `mode` is `'rate'`, null when `'excluded'`.
 */
export const ProductCategorySchema = z.object({
  id: z.number().int(),
  slug: z.string(),
  name_en: z.string(),
  name_dv: z.string().nullable(),
  mode: ProductCategoryModeSchema,
  /** Integer basis points; null exactly when mode is "excluded". */
  rate_bp: z.number().int().nullable(),
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
  name_dv: z.string().max(120).nullable().optional(),
  sort: z.number().int().min(0).max(100000).optional(),
});

/**
 * The mode/rate pair is coherent by construction: an exclusion never
 * carries a rate, a rate override always does. Rates follow the standing-
 * rate sellability law — 50–2000 bp structurally, and rates the active fee
 * tier schedule does not price are refused server-side with a 422
 * `code: rate_not_priced`.
 */
export const CreateProductCategoryRequestSchema = z.discriminatedUnion('mode', [
  productCategoryRequestBase.extend({ mode: z.literal('excluded') }),
  productCategoryRequestBase.extend({
    mode: z.literal('rate'),
    rate_bp: z.number().int().min(50).max(2000),
  }),
]);
export type CreateProductCategoryRequest = z.infer<
  typeof CreateProductCategoryRequestSchema
>;

/**
 * Partial update; omitted keys are untouched. The server validates the
 * FINAL mode/rate pair (post-merge): mode `rate` must end up with a rate,
 * mode `excluded` must end up without one — so switching to `excluded`
 * means sending `{mode: 'excluded', rate_bp: null}`. The slug never
 * changes.
 */
export const UpdateProductCategoryRequestSchema = z.object({
  name_en: z.string().min(1).max(120).optional(),
  name_dv: z.string().max(120).nullable().optional(),
  mode: ProductCategoryModeSchema.optional(),
  rate_bp: z.number().int().min(50).max(2000).nullable().optional(),
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
 * must surface (e.g. 499 → 500 bp: +0.01pp cashback costs +0.26pp all-in).
 */
export const PromotionCostPreviewSchema = z.object({
  promo: RateDescriptionSchema,
  /** Null when no standing rate is effective at the window start. */
  standing: RateDescriptionSchema.nullable(),
  all_in_delta_bp: z.number().int().nullable(),
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
   * Integer basis points, 50–2000 (§4 structural cap), and must exceed the
   * standing rate. Rates the ACTIVE fee tier schedule does not price are
   * refused server-side with a 422 `code: rate_not_priced` — structurally
   * legal but unsellable until the admin publishes a wider schedule.
   */
  rate_bp: z.number().int().min(50).max(2000),
  /** ISO 8601 with an explicit UTC offset, e.g. "2026-09-01T00:00:00+05:00". */
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
export const MerchantProfileSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  slug: z.string(),
  status: MerchantStatusSchema,
  category: z.string().nullable(),
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
  /** An ACTIVE curated store-category slug (422 otherwise), or null. */
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
 * resolves a 6-digit customer code to a MASKED name (e.g. "Ais*** Moh***")
 * so the right person is credited before a manual credit is posted.
 *
 * An unknown code and a known-but-blocked customer answer identically —
 * a plain 200 `{valid: false}` — so the endpoint is no existence oracle.
 * Throttled 30/min per user, matching the credit POST.
 */
export const CustomerLookupResponseSchema = z.discriminatedUnion('valid', [
  z.object({ valid: z.literal(true), masked_name: z.string() }),
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
