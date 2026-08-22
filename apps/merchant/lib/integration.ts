/**
 * Where a merchant's developer is sent after the wizard hands over a token.
 *
 * These are NOT the panel's own API origin (`NEXT_PUBLIC_API_URL`, which is
 * empty in production because the panel is served same-origin) — a vendor
 * needs an absolute base URL they can paste into a till, so the defaults
 * mirror the `servers` block of docs/openapi.yaml and each is overridable
 * per deployment. PLAN §14 flags the domain itself as a placeholder until
 * the production domain is confirmed; changing it is one env var, not a
 * code change.
 *
 * Each variable is read as a LITERAL `process.env.NEXT_PUBLIC_…` expression:
 * Next.js inlines these at build time by textual substitution, so a dynamic
 * lookup would silently be undefined in the browser.
 */

function orDefault(value: string | undefined, fallback: string): string {
  return value !== undefined && value.trim() !== '' ? value.trim() : fallback;
}

/** Production vendor API base — what `Authorization: Bearer` is sent to. */
export const VENDOR_API_BASE_URL = orDefault(
  process.env.NEXT_PUBLIC_VENDOR_API_URL,
  'https://api.manfaa.app/api',
);

/**
 * The narrative guide, as its own page.
 *
 * It used to be folded whole into the spec description, so /docs/ was both
 * documents at once. That stopped on 2026-08-19: the reference page now
 * merges only what you cannot make a single call without (auth, idempotency,
 * errors, testing), because it had grown to ~5,600 words of prose before the
 * first endpoint. Webhooks, retry expectations and the go-live checklist live
 * only here now — so a button labelled "Integration guide" has to point at
 * the guide, not at the reference.
 */
export const INTEGRATION_GUIDE_URL = orDefault(
  process.env.NEXT_PUBLIC_INTEGRATION_GUIDE_URL,
  'https://manfaa.app/docs/integration-guide.html',
);


/**
 * Sandbox credentials are still issued by us, not self-serve: the fixture
 * merchant and its published test customers are shared platform data, so
 * the screen offers a contact rather than a button that cannot exist yet.
 */
export const INTEGRATIONS_EMAIL = orDefault(
  process.env.NEXT_PUBLIC_INTEGRATIONS_EMAIL,
  'integrations@manfaa.app',
);
