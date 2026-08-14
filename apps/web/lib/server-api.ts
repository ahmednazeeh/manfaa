import {
  StoreDetailResponseSchema,
  type StoreDetail,
} from '@manfaa/api-client';

/**
 * Server-side API access for the public storefront. The browser talks to the
 * API same-origin (nginx routes /api/* to Laravel, NEXT_PUBLIC_API_URL is
 * empty in production), but a server component needs an ABSOLUTE URL — a
 * relative fetch has no origin inside the Node process.
 *
 * Resolution order:
 *   1. API_INTERNAL_URL   — runtime-only override (e.g. a direct origin
 *      address that bypasses the CDN edge);
 *   2. NEXT_PUBLIC_API_URL — when the deployment uses an absolute API URL
 *      anyway, reuse it;
 *   3. the canonical public API host in production, localhost in dev.
 */
export function serverApiBaseUrl(): string {
  const internal = process.env.API_INTERNAL_URL;
  if (internal) {
    return internal.replace(/\/$/, '');
  }
  const publicUrl = process.env.NEXT_PUBLIC_API_URL;
  if (publicUrl) {
    return publicUrl.replace(/\/$/, '');
  }
  return process.env.NODE_ENV === 'production'
    ? 'https://api.manfaa.app'
    : 'http://localhost:8000';
}

/** Mirrors the route constraint in routes/api/customer.php. */
const SLUG_PATTERN = /^[a-z0-9-]{1,80}$/;

/**
 * SSR requests all leave this one server's IP, so without an exemption they
 * would share a single 60/min bucket on the API's per-IP discovery throttle —
 * 60+ distinct-slug renders a minute (a crawler, or junk slugs that each miss
 * the data cache) would 429 every SSR fetch and strip the storefront's HTML
 * content. When DISCOVERY_INTERNAL_TOKEN is set (server-only env, must match
 * the API's services.discovery.internal_token), each SSR fetch presents it
 * and the API's `discovery` limiter waves it through. Unset, behaviour is
 * unchanged.
 */
function internalHeaders(): Record<string, string> {
  const token = process.env.DISCOVERY_INTERNAL_TOKEN;
  return token ? { 'X-Discovery-Internal': token } : {};
}

export type StoreLookup =
  | { kind: 'found'; store: StoreDetail }
  | { kind: 'not-found' }
  /** API unreachable or answered garbage — NOT proof the store is gone. */
  | { kind: 'unavailable' };

/**
 * GET /api/discover/merchants/{slug} from the server, for SSR of the public
 * store page. Cached 60s per slug (matching the API's own cache window) so a
 * popular page costs one upstream hit a minute. A 404 is a real answer —
 * unknown and unlisted merchants are indistinguishable by design. Transient
 * failures return `unavailable` so the caller can fall back to client-side
 * fetching instead of minting a false 404.
 */
export async function fetchStoreDetail(slug: string): Promise<StoreLookup> {
  if (!SLUG_PATTERN.test(slug)) {
    return { kind: 'not-found' };
  }

  let response: Response;
  try {
    response = await fetch(
      `${serverApiBaseUrl()}/api/discover/merchants/${slug}`,
      {
        headers: { Accept: 'application/json', ...internalHeaders() },
        next: { revalidate: 60 },
      },
    );
  } catch {
    return { kind: 'unavailable' };
  }

  if (response.status === 404) {
    return { kind: 'not-found' };
  }
  if (!response.ok) {
    return { kind: 'unavailable' };
  }

  const body: unknown = await response.json().catch(() => null);
  const parsed = StoreDetailResponseSchema.safeParse(body);
  if (!parsed.success) {
    return { kind: 'unavailable' };
  }
  return { kind: 'found', store: parsed.data.data };
}
