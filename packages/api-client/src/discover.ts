import { z } from 'zod';
import { apiFetch } from './client';
import {
  dataWrapped,
  MerchantChannelSchema,
  PercentSchema,
  ProductCategoryModeSchema,
} from './resources';

/**
 * Public merchant discovery (Phase 3) — no auth required, throttled per IP,
 * dataset cached 60s server-side. The response carries no customer data and
 * no internal ids beyond the merchant slug.
 */

interface RequestOptions {
  signal?: AbortSignal;
}

/**
 * One located branch of a discoverable store — a bare coordinate pair, all a
 * map pin needs. Deliberately narrower than `StoreBranchSchema`: an entry is
 * repeated across up to seven shelves, so the discovery payload carries no
 * branch names or addresses. Branches without coordinates are absent, never
 * present with nulls.
 */
export const DiscoveryBranchSchema = z.object({
  lat: z.number(),
  lng: z.number(),
});
export type DiscoveryBranch = z.infer<typeof DiscoveryBranchSchema>;

/**
 * One discoverable merchant. `cashback_rate_percent` is the rate the
 * customer gets NOW; `standing_cashback_rate_percent` is the "usually" rate
 * — when they differ, a published promotion is live and `promo_ends_at`
 * says until when. Both are 2-decimal percent strings (PLAN §1 wire
 * format): render them as sent, and compare with `percentToBp`.
 */
export const DiscoveryEntrySchema = z.object({
  name: z.string(),
  /**
   * The store's own name in Thaana; null when it has not supplied one, in
   * which case a Dhivehi UI shows `name` rather than a blank.
   */
  name_dv: z.string().nullable().catch(null),
  slug: z.string(),
  category: z.string().nullable(),
  /** Absolute URL of the merchant logo; null when none is uploaded. */
  logo_url: z.string().nullable(),
  /** Never rendered as the literal "both" — display "In Store & Online". */
  channel: MerchantChannelSchema,
  cashback_rate_percent: PercentSchema,
  standing_cashback_rate_percent: PercentSchema,
  promo_ends_at: z.string().nullable(),
  /** Metres to the nearest branch; null without coordinates. */
  distance_m: z.number().int().nullable(),
  /**
   * Every located branch, for the map view. Empty for an online-only store —
   * such a store belongs on the list and not on the map.
   *
   * `.catch([])` degrades to "no pins" rather than failing the entry, and
   * with it the whole discovery parse: `featured`, `increased`, `nearby` and
   * `online` have no `.catch` of their own, so an API build from before this
   * field existed would blank the landing page during a deploy window.
   */
  branches: z.array(DiscoveryBranchSchema).catch([]),
});
export type DiscoveryEntry = z.infer<typeof DiscoveryEntrySchema>;

/**
 * One chip of the curated category rail: a store category at least one
 * LISTED store carries, in the order the platform curates them (the array
 * is already sorted — never re-sort it alphabetically). `merchant_count` is
 * exactly what `getDirectory({ category: slug })` returns as `meta.total`,
 * so the number on the chip never overstates the grid behind it. Categories
 * no live store carries are absent rather than present with a zero.
 *
 * `name_dv` is the Dhivehi label; render it in Thaana (RTL) and fall back
 * to `name_en` when it is null.
 *
 * Iconography is a PAIR, resolved in this order:
 *   `icon_url` (artwork an admin uploaded)
 *     → `icon` (a curated glyph name)
 *       → the client's own neutral fallback.
 * Both may be null, so a client must always carry that last fallback.
 */
export const DiscoveryCategorySchema = z.object({
  /** Pass back verbatim as the directory's `category` filter. */
  slug: z.string(),
  name_en: z.string(),
  name_dv: z.string().nullable(),
  /** Curated glyph name (lucide), drawn when there is no uploaded icon. */
  icon: z.string().nullable().catch(null),
  /** Absolute URL of uploaded category artwork; null when none. */
  icon_url: z.string().nullable().catch(null),
  merchant_count: z.number().int(),
});
export type DiscoveryCategory = z.infer<typeof DiscoveryCategorySchema>;

/**
 * One curated featured offer — a banner at the top of Discover.
 *
 * Two kinds, never a blend: an `image` banner is the artwork edge to edge
 * with nothing printed over it, a `text` banner is laid out by the client
 * from the words and the live rate. `kind` says which; do not infer it from
 * `image_url`.
 *
 * The offer owns artwork, words and a schedule; every merchant fact is read
 * live by the API, so `merchant.cashback_rate_percent` is what the store is
 * paying RIGHT NOW and never a figure frozen when the banner was made. (On
 * an image banner the artwork speaks for itself — whatever it says is the
 * admin's to keep current.) Offers whose store has stopped trading or whose
 * window has closed simply never arrive.
 */
export const DiscoveryOfferSchema = z.object({
  id: z.number().int(),
  /** Which banner to draw. Older builds sent only image banners. */
  kind: z.enum(['text', 'image']).catch('image'),
  title: z.string(),
  title_dv: z.string().nullable().catch(null),
  blurb: z.string().nullable().catch(null),
  blurb_dv: z.string().nullable().catch(null),
  /** The pill beside the store mark — "Limited time". */
  badge: z.string().nullable().catch(null),
  badge_dv: z.string().nullable().catch(null),
  /** Null on a text banner. */
  image_url: z.string().nullable().catch(null),
  ends_at: z.string().nullable().catch(null),
  merchant: z.object({
    name: z.string(),
    name_dv: z.string().nullable().catch(null),
    slug: z.string(),
    logo_url: z.string().nullable(),
    category: z.string().nullable(),
    channel: MerchantChannelSchema,
    cashback_rate_percent: PercentSchema,
  }),
});
export type DiscoveryOffer = z.infer<typeof DiscoveryOfferSchema>;

export const DiscoverySectionsSchema = z.object({
  featured: z.array(DiscoveryEntrySchema),
  /** Live promo rate above the standing rate. */
  increased: z.array(DiscoveryEntrySchema),
  /** Within 10 km, nearest first; empty without coordinates. */
  nearby: z.array(DiscoveryEntrySchema),
  /**
   * Stores you walk into — channel `in_store` or `both`. The mirror of
   * `online`: both are filtered over the complete listed set and only then
   * capped, so a store is never missing from one because another shelf's
   * ceiling cut it. Never derive this by filtering another shelf.
   *
   * Same deploy-window degrade as `recently_added` below.
   */
  in_store: z.array(DiscoveryEntrySchema).catch([]),
  /** Stores you shop from a browser — channel `online` or `both`. */
  online: z.array(DiscoveryEntrySchema),
  /**
   * Newest listing first — when the store was approved onto the platform,
   * or created for stores the platform onboarded itself. Same entry shape
   * and cap as the shelves above; only the ordering differs.
   *
   * `.catch([])` degrades an unparseable (or absent) shelf to "no shelf"
   * instead of failing the whole discovery parse — an older API build
   * during a deploy window must not blank the landing page.
   */
  recently_added: z.array(DiscoveryEntrySchema).catch([]),
  /**
   * The best rates on the platform right now — standing or promotional,
   * since a shopper only cares what they get today. Already sorted by the
   * API in integer basis points; never re-sort it on the percent STRING.
   */
  top_cashback: z.array(DiscoveryEntrySchema).catch([]),
  /**
   * The curated category rail. Not a shelf: navigation chips above them.
   * Distinct from the directory's `meta.categories`, which is the flat slug
   * list the filter accepts — same categories, less to say. Same
   * deploy-window degrade as `recently_added`.
   */
  categories: z.array(DiscoveryCategorySchema).catch([]),
  /** Curated image banners, already in the admin's own order. */
  offers: z.array(DiscoveryOfferSchema).catch([]),
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
 * directory is not geographic).
 */
export const DirectoryEntrySchema = z.object({
  name: z.string(),
  /** Thaana name; null when unset — fall back to `name`. */
  name_dv: z.string().nullable().catch(null),
  slug: z.string(),
  category: z.string().nullable(),
  /** Absolute URL of the merchant logo; null when none is uploaded. */
  logo_url: z.string().nullable(),
  /** Never rendered as the literal "both" — display "In Store & Online". */
  channel: MerchantChannelSchema,
  cashback_rate_percent: PercentSchema,
  standing_cashback_rate_percent: PercentSchema,
  promo_ends_at: z.string().nullable(),
});
export type DirectoryEntry = z.infer<typeof DirectoryEntrySchema>;

/**
 * `total` is the exact count of the full matching set. `categories` is the
 * distinct category list across ALL listed merchants (unfiltered) for the
 * filter-chip UI — it does not shrink when a filter is applied. Since the
 * curated store-category decision (§1 2026-08-15) the values are category
 * SLUGS from the superadmin-curated list; pass them back verbatim as the
 * `category` filter.
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
  cashback_rate_percent: PercentSchema,
  ends_at: z.string(),
  min_purchase_laari: z.number().int().nullable(),
});
export type StorePromotion = z.infer<typeof StorePromotionSchema>;

/**
 * One row of the public category-rates table (Task #25): "Fruits —
 * excluded, Veggies — 2%, everything else — the standing rate". Display
 * names + mode + rate only — deliberately no ids and no slugs; the public
 * page displays terms, it does not integrate. Excluded categories earn
 * nothing, even during promotions; `cashback_rate_percent` is null exactly
 * when `mode` is "excluded".
 */
export const StoreCategoryRateSchema = z.object({
  name_en: z.string(),
  name_dv: z.string().nullable(),
  mode: ProductCategoryModeSchema,
  cashback_rate_percent: PercentSchema.nullable(),
});
export type StoreCategoryRate = z.infer<typeof StoreCategoryRateSchema>;

export const StoreBranchSchema = z.object({
  name: z.string(),
  address: z.string().nullable(),
  lat: z.number().nullable(),
  lng: z.number().nullable(),
});
export type StoreBranch = z.infer<typeof StoreBranchSchema>;

/**
 * The public store page. `cashback_rate_percent` is the rate the customer
 * gets NOW (the promo rate when one is live);
 * `standing_cashback_rate_percent` is the "usually" rate.
 * `cashback_basis` is the merchant's own eligibility wording, verbatim —
 * displayed to customers, never used in computation.
 */
export const StoreDetailSchema = z.object({
  name: z.string(),
  /** Thaana name; null when unset — fall back to `name`. */
  name_dv: z.string().nullable().catch(null),
  slug: z.string(),
  category: z.string().nullable(),
  /** Absolute URL of the merchant logo; null when none is uploaded. */
  logo_url: z.string().nullable(),
  /** Never rendered as the literal "both" — display "In Store & Online". */
  channel: MerchantChannelSchema,
  featured: z.boolean(),
  cashback_rate_percent: PercentSchema,
  standing_cashback_rate_percent: PercentSchema,
  promotion: StorePromotionSchema.nullable(),
  cashback_basis: z.string().nullable(),
  /**
   * The store's ACTIVE product categories, merchant sort order. Empty for
   * stores without category overrides — "everything else" always earns
   * `standing_cashback_rate_percent` and is NOT a row here; the UI appends
   * that line.
   */
  category_rates: z.array(StoreCategoryRateSchema),
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
