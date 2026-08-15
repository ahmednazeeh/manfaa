import {
  apiFetch,
  bootstrapCsrf,
  dataWrapped,
  MerchantStaffRoleSchema,
  MerchantStatusSchema,
  paginated,
  RateDescriptionSchema,
  TransactionSchema,
  type MerchantStatus,
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
