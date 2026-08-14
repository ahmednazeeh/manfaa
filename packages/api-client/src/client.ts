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
