/**
 * Coming back to where you were sent.
 *
 * A platform sends a shopkeeper straight to the consent screen. If their
 * session has lapsed they land on /login, and without this the request they
 * were answering is simply lost — they sign in, see the dashboard, and have
 * no idea what happened. So the destination rides along as `?next=`.
 */

const PARAM = 'next';

/**
 * Only same-origin paths. A `next` arrives from a URL a stranger can write,
 * so anything absolute — or protocol-relative, which browsers read as
 * absolute — is dropped rather than followed.
 */
export function safeReturnTo(value: string | null | undefined): string | null {
  if (!value || !value.startsWith('/') || value.startsWith('//')) {
    return null;
  }

  return value;
}

/** The `?next=` on the CURRENT url, if it is one we would follow. */
export function readReturnTo(): string | null {
  if (typeof window === 'undefined') {
    return null;
  }

  return safeReturnTo(new URLSearchParams(window.location.search).get(PARAM));
}

/** The login url that will come back here afterwards. */
export function loginUrlReturningTo(pathname: string): string {
  const search = typeof window === 'undefined' ? '' : window.location.search;
  const target = `${pathname}${search}`;

  // The dashboard is where login lands anyway; no need to say so.
  return target === '/' || target === '/dashboard'
    ? '/login'
    : `/login?${PARAM}=${encodeURIComponent(target)}`;
}
