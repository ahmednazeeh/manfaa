import {
  apiFetch,
  bootstrapCsrf,
  dataWrapped,
  paginated,
  TransactionSchema,
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
  role: z.string(),
  merchant: z.object({
    id: z.number().int(),
    name: z.string(),
  }),
});
export type MerchantMe = z.infer<typeof MerchantMeSchema>;

const MeResponseSchema = dataWrapped(MerchantMeSchema);

/** POST /api/merchant/auth/login — bootstraps the Sanctum CSRF cookie first. */
export async function login(body: {
  email: string;
  password: string;
}): Promise<MerchantMe> {
  await bootstrapCsrf();
  const response = await apiFetch('/api/merchant/auth/login', MeResponseSchema, {
    method: 'POST',
    body,
  });
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
