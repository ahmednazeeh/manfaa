import { z } from 'zod';
import { apiFetch } from './client';
import {
  dataWrapped,
  paginated,
  TransactionOriginSchema,
  TransactionStateSchema,
} from './resources';

/**
 * The admin hold-review queue (PLAN §13b task #22).
 *
 * `on_hold` is fraud/dispute review only since task #23 — backdated credits
 * go straight to payable — so every row here is waiting on a human decision,
 * and the two writes below ARE that decision. Admin surface only: there is no
 * merchant- or vendor-facing counterpart by design.
 *
 * All amounts are integer laari; all timestamps are ISO 8601 UTC.
 */

interface RequestOptions {
  signal?: AbortSignal;
}

function queryString(
  params: Record<string, string | number | undefined>,
): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) {
      search.set(key, String(value));
    }
  }
  const encoded = search.toString();
  return encoded === '' ? '' : `?${encoded}`;
}

/**
 * What a release would do to this row, derived server-side from the same rule
 * the release itself runs — so the confirm dialog can promise the 15-day
 * clock exactly when `starts_clock` is true, and never otherwise.
 *
 * `resumes_clock` narrows that promise: the row was ALREADY on the settlement
 * clock when the review opened, so the release advances that clock by the
 * frozen interval instead of granting a fresh 15 days. A sale that was
 * overdue before the hold is still overdue after it — the dialog must not
 * tell an admin the countdown restarts.
 */
export const HoldReleaseTargetSchema = z.object({
  state: TransactionStateSchema,
  starts_clock: z.boolean(),
  resumes_clock: z.boolean(),
});
export type HoldReleaseTarget = z.infer<typeof HoldReleaseTargetSchema>;

export const HoldSchema = z.object({
  id: z.number().int(),
  state: TransactionStateSchema,
  merchant: z.object({
    id: z.number().int(),
    name: z.string(),
    slug: z.string(),
  }),
  /** Masked on the server — a code plus "Ais*** Moh***", never a full record. */
  customer: z
    .object({ customer_code: z.string(), masked_name: z.string() })
    .nullable(),
  invoice_no: z.string(),
  origin: TransactionOriginSchema,
  currency: z.string(),
  eligible_laari: z.number().int(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  /** cashback + fee + GST as stored: what a reject mirrors out of the ledger. */
  accrued_laari: z.number().int(),
  /** False on a zeroed credit — rejecting it books nothing. */
  has_accrual: z.boolean(),
  /**
   * The HOLD's reason, read from the append-only event history (the row's own
   * reason_code is rewritten by later hops). A plain string, not the reason
   * enum: an older production row can carry a code this build predates, so
   * label helpers must fall back to prose rather than print the code.
   */
  reason_code: z.string().nullable(),
  /** PLAN §1: credited outside the validation window, final for the merchant. */
  backdated: z.boolean(),
  occurred_at: z.string(),
  held_at: z.string().nullable(),
  held_by: z
    .object({
      actor_type: z.string(),
      actor_id: z.number().int().nullable(),
    })
    .nullable(),
  /** Whole days the review has been open; null when the hold predates the log. */
  age_days: z.number().int().nullable(),
  pre_hold_state: TransactionStateSchema.nullable(),
  release_target: HoldReleaseTargetSchema,
});
export type Hold = z.infer<typeof HoldSchema>;

/**
 * Counts over EVERY hold, not the filtered page: `total` drives the nav badge
 * while an admin is looking at one store, and the two lists populate the
 * filter pickers with values that actually exist.
 */
export const HoldSummarySchema = z.object({
  total: z.number().int(),
  reasons: z.array(
    z.object({
      reason_code: z.string().nullable(),
      count: z.number().int(),
    }),
  ),
  merchants: z.array(
    z.object({
      id: z.number().int(),
      name: z.string(),
      count: z.number().int(),
    }),
  ),
});
export type HoldSummary = z.infer<typeof HoldSummarySchema>;

export const HoldListResponseSchema = paginated(HoldSchema).extend({
  summary: HoldSummarySchema,
});
export type HoldListResponse = z.infer<typeof HoldListResponseSchema>;

/**
 * The transaction after a decision. clock_start_at and due_at are here on
 * purpose: a release that lands in payable_unfunded comes back with both
 * stamped, so the panel shows the §7 clock running rather than assuming it.
 */
export const HoldOutcomeSchema = z.object({
  id: z.number().int(),
  state: TransactionStateSchema,
  reason_code: z.string().nullable(),
  backdated: z.boolean(),
  currency: z.string(),
  cashback_laari: z.number().int(),
  fee_laari: z.number().int(),
  fee_gst_laari: z.number().int(),
  clock_start_at: z.string().nullable(),
  due_at: z.string().nullable(),
});
export type HoldOutcome = z.infer<typeof HoldOutcomeSchema>;

export const HoldOutcomeResponseSchema = dataWrapped(HoldOutcomeSchema);
export type HoldOutcomeResponse = z.infer<typeof HoldOutcomeResponseSchema>;

/** GET /api/admin/holds — oldest hold first, optionally filtered. */
export function listHolds(
  params: { reason?: string; merchant_id?: number; page?: number } = {},
  options: RequestOptions = {},
): Promise<HoldListResponse> {
  return apiFetch(
    `/api/admin/holds${queryString({
      reason: params.reason,
      merchant_id: params.merchant_id,
      page: params.page,
    })}`,
    HoldListResponseSchema,
    { signal: options.signal },
  );
}

/**
 * POST /api/admin/holds/{id}/release — the review cleared.
 *
 * The target state is the SERVER's to derive, never the caller's: past the
 * store's validation window the sale becomes payable with the 15-day clock
 * starting now — or, for a row that was already on the clock, resuming where
 * the hold froze it; inside the window, the sale returns to the state it was
 * held from (never `tracked`, which nothing sweeps).
 * The note is optional and kept verbatim on the transaction's event history.
 *
 * Refuses 409 `not_on_hold` if the row moved since the queue was loaded.
 */
export function releaseHold(
  id: number,
  body: { note?: string } = {},
): Promise<HoldOutcomeResponse> {
  return apiFetch(`/api/admin/holds/${id}/release`, HoldOutcomeResponseSchema, {
    method: 'POST',
    body,
  });
}

/**
 * POST /api/admin/holds/{id}/reject — the review failed.
 *
 * The sale reverses and its accrual is mirrored out of the ledger from the
 * stored integers. The reason is REQUIRED and kept verbatim on the event.
 *
 * Refuses 409 `not_on_hold` (the row is no longer held — a confirmed or paid
 * reward can only be corrected by an adjustment) or 409 `locked_in_settlement`
 * (the line is frozen in a batch that has left draft; deal with the settlement
 * first, or the cashback would stand and the hold would remain).
 */
export function rejectHold(
  id: number,
  body: { reason: string },
): Promise<HoldOutcomeResponse> {
  return apiFetch(`/api/admin/holds/${id}/reject`, HoldOutcomeResponseSchema, {
    method: 'POST',
    body,
  });
}
