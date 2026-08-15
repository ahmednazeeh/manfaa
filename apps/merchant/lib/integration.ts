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

/** Sandbox base (PLAN §9.5): fixture merchants, no real cashback. */
export const SANDBOX_API_BASE_URL = orDefault(
  process.env.NEXT_PUBLIC_SANDBOX_API_URL,
  'https://sandbox.api.manfaa.app/api',
);

/** The vendor integration guide (docs/integration-guide.md, published). */
export const INTEGRATION_GUIDE_URL = orDefault(
  process.env.NEXT_PUBLIC_INTEGRATION_GUIDE_URL,
  'https://manfaa.app/docs/integration-guide',
);

/** Its sandbox section — fixtures, test customer codes, the go-live path. */
export const SANDBOX_GUIDE_URL = orDefault(
  process.env.NEXT_PUBLIC_SANDBOX_GUIDE_URL,
  `${INTEGRATION_GUIDE_URL}#sandbox-fixtures`,
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
