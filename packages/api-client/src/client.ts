import type { z } from 'zod';

/** Base URL of the Laravel API, without a trailing slash. */
export function apiBaseUrl(): string {
  const url = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';
  return url.replace(/\/$/, '');
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: unknown,
  ) {
    super(`API request failed with status ${status}`);
    this.name = 'ApiError';
  }
}

/**
 * The machine-readable `code` an API refusal carries on its body — e.g.
 * `duplicate_bank_ref` (409), `slip_unsupported_type` (422),
 * `backdated_irreversible` (409), `permission_required` (403). Returns null
 * for anything else (a plain validation error, a network failure, a
 * non-ApiError throw), so a caller can branch on the code it knows and fall
 * back to the status otherwise.
 *
 * A `permission_required` body also carries `permission` — the one slug the
 * account is missing — so a refusal can name what it would take rather than
 * just saying forbidden. Read it off `error.body`; this helper returns the
 * code alone.
 */
export function apiErrorCode(error: unknown): string | null {
  if (!(error instanceof ApiError) || typeof error.body !== 'object') {
    return null;
  }
  const code = (error.body as { code?: unknown } | null)?.code;
  return typeof code === 'string' ? code : null;
}

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') {
    return null;
  }
  const prefix = `${name}=`;
  const entry = document.cookie
    .split('; ')
    .find((part) => part.startsWith(prefix));
  return entry ? decodeURIComponent(entry.slice(prefix.length)) : null;
}

/**
 * Sanctum CSRF bootstrap: call once before the first credentialed write so the
 * XSRF-TOKEN cookie exists. Subsequent apiFetch calls echo it automatically.
 */
export async function bootstrapCsrf(): Promise<void> {
  await fetch(`${apiBaseUrl()}/sanctum/csrf-cookie`, {
    credentials: 'include',
  });
}

export interface ApiFetchOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  /** JSON-serialised into the request body; FormData is sent as-is. */
  body?: unknown;
  headers?: Record<string, string>;
  signal?: AbortSignal;
}

/**
 * Fetch wrapper for the Manfaa API: Sanctum cookie auth (credentials include),
 * JSON in/out, response validated against the given zod schema.
 */
export async function apiFetch<Schema extends z.ZodType>(
  path: string,
  schema: Schema,
  options: ApiFetchOptions = {},
): Promise<z.output<Schema>> {
  const { method = 'GET', body, headers = {}, signal } = options;
  const xsrfToken = readCookie('XSRF-TOKEN');
  const isFormData = typeof FormData !== 'undefined' && body instanceof FormData;

  const response = await fetch(`${apiBaseUrl()}${path}`, {
    method,
    credentials: 'include',
    signal,
    headers: {
      Accept: 'application/json',
      // FormData sets its own multipart boundary Content-Type.
      ...(body !== undefined && !isFormData
        ? { 'Content-Type': 'application/json' }
        : {}),
      ...(xsrfToken !== null ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
      ...headers,
    },
    body:
      body === undefined ? undefined : isFormData ? body : JSON.stringify(body),
  });

  const payload =
    response.status === 204
      ? undefined
      : await response.json().catch(() => undefined);

  if (!response.ok) {
    throw new ApiError(response.status, payload);
  }
  return schema.parse(payload);
}

/**
 * Like apiFetch but for endpoints that stream BINARY (e.g. the admin
 * settlement-slip route, whose file lives on a private disk with no URL):
 * performs the credentialed GET and returns the bytes as a Blob, which the
 * caller turns into an object URL for an <img>/<iframe> and revokes when the
 * preview closes. Errors still arrive as JSON and are thrown as ApiError, so
 * "no slip on this batch" (404) is a catchable refusal rather than a broken
 * image.
 */
export async function apiFetchBlob(
  path: string,
  options: Omit<ApiFetchOptions, 'body'> = {},
): Promise<Blob> {
  const { method = 'GET', headers = {}, signal } = options;
  const xsrfToken = readCookie('XSRF-TOKEN');

  const response = await fetch(`${apiBaseUrl()}${path}`, {
    method,
    credentials: 'include',
    signal,
    headers: {
      // JSON first so a refusal comes back as JSON rather than an HTML error
      // page; the stream itself ignores content negotiation.
      Accept: 'application/json, */*',
      ...(xsrfToken !== null ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
      ...headers,
    },
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => undefined);
    throw new ApiError(response.status, payload);
  }
  return response.blob();
}

/**
 * Like apiFetch but for endpoints returning a non-JSON body (e.g. a CSV
 * attachment): performs the credentialed request — echoing the Sanctum CSRF
 * token so state-mutating methods pass verification — and returns the raw
 * response text. Errors still arrive as JSON and are thrown as ApiError.
 */
export async function apiFetchText(
  path: string,
  options: Omit<ApiFetchOptions, 'body'> = {},
): Promise<string> {
  const { method = 'GET', headers = {}, signal } = options;
  const xsrfToken = readCookie('XSRF-TOKEN');

  const response = await fetch(`${apiBaseUrl()}${path}`, {
    method,
    credentials: 'include',
    signal,
    headers: {
      ...(xsrfToken !== null ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
      ...headers,
    },
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => undefined);
    throw new ApiError(response.status, payload);
  }
  return response.text();
}
