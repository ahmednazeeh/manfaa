import { ApiError } from '@manfaa/api-client';

/**
 * Extracts a human-readable message from a failed API call. Laravel error
 * bodies carry `{ message, errors? }`; anything else falls back to the
 * HTTP status or the given default.
 */
export function apiErrorMessage(
  error: unknown,
  fallback = 'Something went wrong. Please try again.',
): string {
  if (error instanceof ApiError) {
    const body = error.body as
      { message?: unknown; errors?: unknown } | null | undefined;

    if (
      body &&
      typeof body.message === 'string' &&
      body.message.trim() !== ''
    ) {
      return body.message;
    }
    return `Request failed with status ${error.status}.`;
  }
  if (error instanceof Error && error.message !== '') {
    return error.message;
  }
  return fallback;
}

/**
 * The per-field half of a Laravel 422 — `{ errors: { field: [msg, …] } }` —
 * flattened to one message per field so a form can mark the exact input the
 * server refused instead of only repeating the summary sentence.
 *
 * Empty for every other failure, including a 422 raised with `abort(422,
 * '…')`: that one carries prose and no field map, and `apiErrorMessage` is
 * what shows it.
 */
export function apiFieldErrors(error: unknown): Record<string, string> {
  if (!(error instanceof ApiError)) {
    return {};
  }

  const body = error.body as { errors?: unknown } | null | undefined;
  if (!body || typeof body.errors !== 'object' || body.errors === null) {
    return {};
  }

  const out: Record<string, string> = {};
  for (const [field, messages] of Object.entries(
    body.errors as Record<string, unknown>,
  )) {
    const first = Array.isArray(messages) ? messages[0] : messages;
    if (typeof first === 'string' && first.trim() !== '') {
      out[field] = first;
    }
  }
  return out;
}

/** True when the failure is an authentication failure (session expired). */
export function isUnauthenticated(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}
