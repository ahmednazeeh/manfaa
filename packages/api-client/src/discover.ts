import { z } from 'zod';
import { apiFetch } from './client';
import { dataWrapped } from './resources';

/**
 * Public merchant discovery (Phase 3) — no auth required, throttled per IP,
 * dataset cached 60s server-side. The response carries no customer data and
 * no internal ids beyond the merchant slug.
 */

interface RequestOptions {
  signal?: AbortSignal;
}

/**
 * One discoverable merchant. `rate_bp` is the rate the customer gets NOW;
 * `standing_rate_bp` is the "usually" rate — when they differ, a published
 * promotion is live and `promo_ends_at` says until when.
 */
export const DiscoveryEntrySchema = z.object({
  name: z.string(),
  slug: z.string(),
  category: z.string().nullable(),
  /** Absolute URL of the merchant logo; null when none is uploaded. */
  logo_url: z.string().nullable(),
  rate_bp: z.number().int(),
  standing_rate_bp: z.number().int(),
  promo_ends_at: z.string().nullable(),
  /** Metres to the nearest branch; null without coordinates. */
  distance_m: z.number().int().nullable(),
});
export type DiscoveryEntry = z.infer<typeof DiscoveryEntrySchema>;

export const DiscoverySectionsSchema = z.object({
  featured: z.array(DiscoveryEntrySchema),
  /** Live promo rate above the standing rate. */
  increased: z.array(DiscoveryEntrySchema),
  /** Within 10 km, nearest first; empty without coordinates. */
  nearby: z.array(DiscoveryEntrySchema),
  online: z.array(DiscoveryEntrySchema),
});
export type DiscoverySections = z.infer<typeof DiscoverySectionsSchema>;

export const DiscoveryResponseSchema = dataWrapped(DiscoverySectionsSchema);
export type DiscoveryResponse = z.infer<typeof DiscoveryResponseSchema>;

/**
 * GET /api/discover — public, no auth. Coordinates travel as a pair or not
 * at all; without them the `nearby` section is empty and every `distance_m`
 * is null.
 */
export function getDiscovery(
  params: { lat?: number; lng?: number } = {},
  options: RequestOptions = {},
): Promise<DiscoveryResponse> {
  const search = new URLSearchParams();
  if (params.lat !== undefined && params.lng !== undefined) {
    search.set('lat', String(params.lat));
    search.set('lng', String(params.lng));
  }
  const encoded = search.toString();
  return apiFetch(
    `/api/discover${encoded === '' ? '' : `?${encoded}`}`,
    DiscoveryResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Storefront directory (paginated /stores index)
// ---------------------------------------------------------------------------

/**
 * One directory row: the discovery entry shape minus `distance_m` (the
 * directory is not geographic) plus `is_online`.
 */
export const DirectoryEntrySchema = z.object({
  name: z.string(),
  slug: z.string(),
  category: z.string().nullable(),
  /** Absolute URL of the merchant logo; null when none is uploaded. */
  logo_url: z.string().nullable(),
  is_online: z.boolean(),
  rate_bp: z.number().int(),
  standing_rate_bp: z.number().int(),
  promo_ends_at: z.string().nullable(),
});
export type DirectoryEntry = z.infer<typeof DirectoryEntrySchema>;

/**
 * `total` is the exact count of the full matching set. `categories` is the
 * distinct category list across ALL listed merchants (unfiltered) for the
 * filter-chip UI — it does not shrink when a filter is applied.
 */
export const DirectoryMetaSchema = z.object({
  total: z.number().int(),
  page: z.number().int(),
  per_page: z.number().int(),
  categories: z.array(z.string()),
});
export type DirectoryMeta = z.infer<typeof DirectoryMetaSchema>;

export const DirectoryResponseSchema = z.object({
  data: z.array(DirectoryEntrySchema),
  meta: DirectoryMetaSchema,
});
export type DirectoryResponse = z.infer<typeof DirectoryResponseSchema>;

export interface DirectoryParams {
  /** Name substring, 2–40 chars after trimming; shorter strings are rejected. */
  q?: string;
  /** Exact category match, as returned in `meta.categories`. */
  category?: string;
  /** 1-based. */
  page?: number;
  /** 1–24; server default 12. */
  per_page?: number;
}

/**
 * GET /api/discover/merchants — public, no auth, throttled per IP.
 * Alphabetical, filterable by name search and category, paginated.
 */
export function getDirectory(
  params: DirectoryParams = {},
  options: RequestOptions = {},
): Promise<DirectoryResponse> {
  const search = new URLSearchParams();
  if (params.q !== undefined && params.q !== '') {
    search.set('q', params.q);
  }
  if (params.category !== undefined && params.category !== '') {
    search.set('category', params.category);
  }
  if (params.page !== undefined) {
    search.set('page', String(params.page));
  }
  if (params.per_page !== undefined) {
    search.set('per_page', String(params.per_page));
  }
  const encoded = search.toString();
  return apiFetch(
    `/api/discover/merchants${encoded === '' ? '' : `?${encoded}`}`,
    DirectoryResponseSchema,
    { signal: options.signal },
  );
}

// ---------------------------------------------------------------------------
// Store detail (public /store/{slug} page)
// ---------------------------------------------------------------------------

/**
 * The live published promotion beating the standing rate; null when none.
 * Promotions are immutable once published, so rate and end are safe to show.
 */
export const StorePromotionSchema = z.object({
  rate_bp: z.number().int(),
  ends_at: z.string(),
  min_purchase_laari: z.number().int().nullable(),
});
export type StorePromotion = z.infer<typeof StorePromotionSchema>;

export const StoreBranchSchema = z.object({
  name: z.string(),
  address: z.string().nullable(),
  lat: z.number().nullable(),
  lng: z.number().nullable(),
});
export type StoreBranch = z.infer<typeof StoreBranchSchema>;

/**
 * The public store page. `rate_bp` is the rate the customer gets NOW (the
 * promo rate when one is live); `standing_rate_bp` is the "usually" rate.
 * `cashback_basis` is the merchant's own eligibility wording, verbatim —
 * displayed to customers, never used in computation.
 */
export const StoreDetailSchema = z.object({
  name: z.string(),
  slug: z.string(),
  category: z.string().nullable(),
  /** Absolute URL of the merchant logo; null when none is uploaded. */
  logo_url: z.string().nullable(),
  is_online: z.boolean(),
  featured: z.boolean(),
  rate_bp: z.number().int(),
  standing_rate_bp: z.number().int(),
  promotion: StorePromotionSchema.nullable(),
  cashback_basis: z.string().nullable(),
  branches: z.array(StoreBranchSchema),
  /**
   * Month the merchant joined, machine form "YYYY-MM" (month granularity,
   * business timezone). The UI composes and localises the label itself.
   * `.catch(null)` degrades to "no label" instead of failing the whole
   * store parse if an older API build is still serving the field's
   * predecessor (`joined_label`) during a deploy window.
   */
  joined: z.string().nullable().catch(null),
});
export type StoreDetail = z.infer<typeof StoreDetailSchema>;

export const StoreDetailResponseSchema = dataWrapped(StoreDetailSchema);
export type StoreDetailResponse = z.infer<typeof StoreDetailResponseSchema>;

/**
 * GET /api/discover/merchants/{slug} — public, no auth, throttled per IP.
 * 404s identically for unknown, suspended, closed and offer-less slugs; the
 * response never reveals that a merchant exists but is not active.
 */
export function getStore(
  slug: string,
  options: RequestOptions = {},
): Promise<StoreDetailResponse> {
  return apiFetch(
    `/api/discover/merchants/${encodeURIComponent(slug)}`,
    StoreDetailResponseSchema,
    { signal: options.signal },
  );
}
