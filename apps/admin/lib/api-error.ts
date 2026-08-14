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

/** True when the failure is an authentication failure (session expired). */
export function isUnauthenticated(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}
