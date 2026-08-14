import {
  apiFetch,
  dataWrapped,
  DiscoveryEntrySchema,
} from '@manfaa/api-client';
import { z } from 'zod';

/**
 * The claim form's merchant picker is fed from the public discovery feed
 * (§10: "merchant picker from discovery data").
 *
 * Claim submission (`POST /api/customer/claims`) identifies the merchant by
 * SLUG — the only identifier discovery publishes (a deliberate privacy
 * choice in DiscoveryService) — and the API resolves it server-side.
 */
const ClaimDiscoveryResponseSchema = dataWrapped(
  z.object({
    featured: z.array(DiscoveryEntrySchema),
    increased: z.array(DiscoveryEntrySchema),
    nearby: z.array(DiscoveryEntrySchema),
    online: z.array(DiscoveryEntrySchema),
  }),
);

export interface ClaimMerchant {
  name: string;
  slug: string;
}

/** All discoverable merchants, deduplicated across sections, name-sorted. */
export async function fetchClaimMerchants(
  signal?: AbortSignal,
): Promise<ClaimMerchant[]> {
  const response = await apiFetch(
    '/api/discover',
    ClaimDiscoveryResponseSchema,
    { signal },
  );

  const seen = new Map<string, ClaimMerchant>();
  for (const section of [
    response.data.featured,
    response.data.increased,
    response.data.nearby,
    response.data.online,
  ]) {
    for (const entry of section) {
      if (!seen.has(entry.slug)) {
        seen.set(entry.slug, { name: entry.name, slug: entry.slug });
      }
    }
  }

  return Array.from(seen.values()).sort((a, b) => a.name.localeCompare(b.name));
}
