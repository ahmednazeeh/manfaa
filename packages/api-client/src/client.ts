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
 * The SAME GET as apiFetch, deliberately WITHOUT the session: no cookies are
 * sent (`credentials: 'omit'`) and no CSRF token is echoed.
 *
 * For the endpoints that are unauthenticated BY DESIGN and whose answer must
 * not depend on who is asking — today the merchant landing page's fee-promotion
 * banner (`/api/public/fee-promotion`), which is served from the same origin as
 * the logged-in merchant panel and would otherwise carry that merchant's
 * session cookie on every visit by a stranger. A public endpoint that receives
 * a session invites a public endpoint that reads one; omitting the credentials
 * at the client makes that impossible rather than merely unintended.
 *
 * It also keeps the answer cacheable: a request with no credentials is the same
 * request for every visitor, which is what the server's 60-second cache assumes.
 *
 * Not a general replacement for apiFetch on public routes — `/api/discover*`
 * keeps its existing credentialed path, because those responses are already
 * built and shipped that way and switching them is a behaviour change with no
 * caller asking for it.
 */
export async function apiFetchPublic<Schema extends z.ZodType>(
  path: string,
  schema: Schema,
  options: Omit<ApiFetchOptions, 'body' | 'method'> = {},
): Promise<z.output<Schema>> {
  const { headers = {}, signal } = options;

  const response = await fetch(`${apiBaseUrl()}${path}`, {
    method: 'GET',
    credentials: 'omit',
    signal,
    headers: {
      Accept: 'application/json',
      ...headers,
    },
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
  return (await apiFetchDownload(path, options)).blob;
}

/** The bytes of a download plus the name the server asked it be saved as. */
export interface ApiDownload {
  blob: Blob;
  /** From Content-Disposition; null when the response did not name the file. */
  filename: string | null;
}

/**
 * The same credentialed binary fetch as apiFetchBlob, but keeping the one
 * header a blob throws away: Content-Disposition. A slip is shown on screen,
 * so its bytes are the whole story; an ATTACHMENT is saved to disk, and the
 * server is the only party that knows what it should be called — the report
 * export names itself `manfaa-{report}-{from}-{to}.xlsx`, and re-deriving
 * that in the panel would be a second copy of a rule the API already owns.
 *
 * Errors still arrive as JSON and are thrown as ApiError, so the row-cap
 * refusal (422 `report_too_large`) is catchable rather than a corrupt file.
 */
export async function apiFetchDownload(
  path: string,
  options: Omit<ApiFetchOptions, 'body'> = {},
): Promise<ApiDownload> {
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

  return {
    blob: await response.blob(),
    filename: filenameFromContentDisposition(
      response.headers.get('Content-Disposition'),
    ),
  };
}

/** `filename*=UTF-8''name.xlsx` — RFC 5987, preferred when both are present. */
const CONTENT_DISPOSITION_EXTENDED =
  /filename\*\s*=\s*(?:[\w-]+)?'[^']*'([^;]+)/i;
/** `filename="name.xlsx"`, quotes allowed to contain escaped characters. */
const CONTENT_DISPOSITION_QUOTED = /filename\s*=\s*"((?:[^"\\]|\\.)*)"/i;
/** `filename=name.xlsx` — unquoted token, ends at the next parameter. */
const CONTENT_DISPOSITION_PLAIN = /filename\s*=\s*([^;]+)/i;

/**
 * The download name a Content-Disposition header carries, or null when it
 * carries none. Any directory part is dropped: the value ends up as the
 * `download` attribute of an anchor, and a server-supplied "../" has no
 * business steering where a browser writes.
 */
export function filenameFromContentDisposition(
  header: string | null | undefined,
): string | null {
  if (!header) {
    return null;
  }

  const extended = CONTENT_DISPOSITION_EXTENDED.exec(header);
  if (extended?.[1] !== undefined) {
    return sanitiseFilename(decodePercent(extended[1].trim()));
  }

  const quoted = CONTENT_DISPOSITION_QUOTED.exec(header);
  if (quoted?.[1] !== undefined) {
    return sanitiseFilename(quoted[1].replace(/\\(.)/g, '$1'));
  }

  const plain = CONTENT_DISPOSITION_PLAIN.exec(header);
  if (plain?.[1] !== undefined) {
    return sanitiseFilename(plain[1].trim());
  }

  return null;
}

function decodePercent(value: string): string {
  try {
    return decodeURIComponent(value);
  } catch {
    // A malformed escape is not worth losing the whole name over.
    return value;
  }
}

function sanitiseFilename(value: string): string | null {
  const base = value.split(/[\\/]/).pop()?.trim() ?? '';
  return base === '' || base === '.' || base === '..' ? null : base;
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
