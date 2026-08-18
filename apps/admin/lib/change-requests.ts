import {
  apiFetch,
  ChangeRequestKindSchema,
  ChangeRequestStatusSchema,
  MerchantChangeRequestSchema,
  MerchantStatusSchema,
  type ChangeRequestKind,
  type ChangeRequestStatus,
  type MerchantChangeRequest,
  type MerchantStatus,
} from '@manfaa/api-client';
import { z } from 'zod';

/**
 * The admin half of the store-CHANGE review queue (MR9) — the sibling of the
 * store-approval queue. That queue decides whether a store may go live at
 * all; this one decides whether a store that IS live may change what a
 * shopper reads and trusts: its name, category, channel, logo, website, its
 * "what earns cashback" promise, and its branch estate.
 *
 * The REQUEST OBJECT itself is not defined here. It is byte-identical on
 * every surface — this console, the merchant panel, the merchant app — so it
 * lives in @manfaa/api-client with the rest of the shared wire, and only the
 * admin-only endpoints and the console's own reading helpers are local.
 */

/** Every kind, in the order the queue's filter offers them. */
export const CHANGE_KINDS: readonly ChangeRequestKind[] =
  ChangeRequestKindSchema.options;

/** True for the three kinds that act on one address rather than the profile. */
export function isBranchKind(kind: ChangeRequestKind): boolean {
  return kind !== 'profile';
}

export const ChangeRequestCountsSchema = z.object({
  pending: z.number().int(),
  approved: z.number().int(),
  rejected: z.number().int(),
  superseded: z.number().int(),
});
export type ChangeRequestCounts = z.infer<typeof ChangeRequestCountsSchema>;

export const ChangeRequestListResponseSchema = z.object({
  data: z.array(MerchantChangeRequestSchema),
  meta: z.object({
    /** The status the listing was filtered to (default `pending`). */
    status: ChangeRequestStatusSchema,
    /** Tab badges: how many requests sit in each state, ignoring the kind filter. */
    counts: ChangeRequestCountsSchema,
  }),
});
export type ChangeRequestListResponse = z.infer<
  typeof ChangeRequestListResponseSchema
>;

export const ChangeRequestResponseSchema = z.object({
  data: MerchantChangeRequestSchema,
});
export type ChangeRequestResponse = z.infer<typeof ChangeRequestResponseSchema>;

interface RequestOptions {
  signal?: AbortSignal;
}

export interface ChangeRequestFilters {
  status?: ChangeRequestStatus;
  kind?: ChangeRequestKind;
  merchant_id?: number;
}

/**
 * GET /api/admin/change-requests — `pending` by default and oldest first (a
 * queue is a queue); decided states come back newest first. Capped at 200
 * rows, so the console filters rather than pages. Any admin may read it.
 */
export function listChangeRequests(
  filters: ChangeRequestFilters = {},
  options: RequestOptions = {},
): Promise<ChangeRequestListResponse> {
  const params = new URLSearchParams();
  if (filters.status !== undefined) {
    params.set('status', filters.status);
  }
  if (filters.kind !== undefined) {
    params.set('kind', filters.kind);
  }
  if (filters.merchant_id !== undefined) {
    params.set('merchant_id', String(filters.merchant_id));
  }
  const query = params.size > 0 ? `?${params.toString()}` : '';

  return apiFetch(
    `/api/admin/change-requests${query}`,
    ChangeRequestListResponseSchema,
    { signal: options.signal },
  );
}

/**
 * GET /api/admin/change-requests/{id} — the same object, read fresh. The
 * review drawer opens on it so a decision is never taken against a row that
 * was superseded, or decided in another tab, while the list sat open.
 */
export function getChangeRequest(
  id: number,
  options: RequestOptions = {},
): Promise<ChangeRequestResponse> {
  return apiFetch(
    `/api/admin/change-requests/${id}`,
    ChangeRequestResponseSchema,
    { signal: options.signal },
  );
}

/**
 * POST /api/admin/change-requests/{id}/approve — superadmin only. Applies the
 * change atomically (profile fields including a staged logo, or the branch
 * create/update/delete), busts the discovery read models and pushes the
 * outcome to the merchant staff who could have submitted it. 409 with
 * `change_not_pending`, `branch_missing` or `branch_referenced`.
 */
export function approveChangeRequest(
  id: number,
  options: RequestOptions = {},
): Promise<ChangeRequestResponse> {
  return apiFetch(
    `/api/admin/change-requests/${id}/approve`,
    ChangeRequestResponseSchema,
    { method: 'POST', signal: options.signal },
  );
}

export interface RejectChangeRequestBody {
  /** Reaches the merchant verbatim in the push — write it for them. */
  reason: string;
}

/**
 * POST /api/admin/change-requests/{id}/reject — superadmin only. Nothing is
 * applied, a staged logo is discarded, and the reason travels to the
 * merchant. 422 without a reason, 409 `change_not_pending`.
 */
export function rejectChangeRequest(
  id: number,
  body: RejectChangeRequestBody,
  options: RequestOptions = {},
): Promise<ChangeRequestResponse> {
  return apiFetch(
    `/api/admin/change-requests/${id}/reject`,
    ChangeRequestResponseSchema,
    { method: 'POST', body, signal: options.signal },
  );
}

/**
 * What the request is ABOUT, in words a reviewer can scan: the store itself
 * for a profile change, otherwise the branch — by its snapshotted name, by
 * the name proposed for a brand-new one, or by its id when a partial update
 * snapshotted no name at all (the snapshot holds only the fields in play).
 */
export function changeTargetName(request: MerchantChangeRequest): string {
  if (!isBranchKind(request.kind)) {
    return 'Store profile';
  }

  if (request.branch_name !== null && request.branch_name !== '') {
    return request.branch_name;
  }

  const proposedName = request.proposed.name;
  if (typeof proposedName === 'string' && proposedName !== '') {
    return proposedName;
  }

  return request.branch_id === null
    ? 'New branch'
    : `Branch #${request.branch_id}`;
}

/** The store a request belongs to, for the table row and the search box. */
export function changeStoreName(request: MerchantChangeRequest): string {
  return request.merchant?.name ?? `Store #${request.merchant_id}`;
}

const MERCHANT_STATUSES: readonly string[] = MerchantStatusSchema.options;

/**
 * The store's lifecycle status as a typed union, so it can reach the shared
 * status chip. The wire types this key as a plain string on the shared
 * request schema; anything this build does not recognise renders no chip
 * rather than leaking machine code into the drawer.
 */
export function changeStoreStatus(
  request: MerchantChangeRequest,
): MerchantStatus | null {
  const status = request.merchant?.status;
  return status !== undefined && MERCHANT_STATUSES.includes(status)
    ? (status as MerchantStatus)
    : null;
}
