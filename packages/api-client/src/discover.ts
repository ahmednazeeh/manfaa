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
