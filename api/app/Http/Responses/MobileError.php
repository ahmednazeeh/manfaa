<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The ONE error shape the mobile tree emits.
 *
 *   { "error": { "code": "...", "message": "...", "meta": { ... } } }
 *
 * `code` is the contract — stable, machine-readable, what the apps switch on
 * and localise into Dhivehi and English.
 *
 * `message` is the FALLBACK, and it is the reason this class exists. The
 * apps localise codes they know; a code shipped by a server deploy is
 * unknown to every build already on a phone, and this project's own rule is
 * that a customer never sees a raw snake_case reason code. So the server
 * always sends a sentence alongside the code, and a client that does not
 * recognise the code renders that instead of `rate_not_priced`. An app three
 * months old therefore degrades to plain English rather than to gibberish.
 *
 * SCOPE: `api/mobile/*` only. The panels keep Laravel's stock shapes and the
 * /v1 vendor contract is published and immutable — neither may drift because
 * the apps wanted something tidier.
 */
final class MobileError
{
    /**
     * Sentences for the codes this layer raises itself. Domain codes are
     * open-ended and are not enumerated here; they fall through to the
     * exception's own message when that message is prose.
     */
    private const array MESSAGES = [
        'unauthenticated' => 'Please sign in again.',
        'validation_failed' => 'Some of the details entered need correcting.',
        'rate_limited' => 'Too many attempts. Please wait a moment and try again.',
        'not_found' => 'That could not be found.',
        'method_not_allowed' => 'That request is not supported.',
        'forbidden' => 'You do not have permission to do that.',
        'conflict' => 'That could not be completed as things stand.',
        'server_error' => 'Something went wrong at our end. Please try again.',
        'service_unavailable' => 'The service is briefly unavailable. Please try again.',
    ];

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function response(string $code, int $status, ?string $message = null, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'error' => array_filter([
                'code' => $code,
                'message' => $message ?? self::MESSAGES[$code] ?? self::MESSAGES['server_error'],
                'meta' => $meta,
            ], static fn ($value) => $value !== []),
        ], $status);
    }

    /**
     * Render a thrown exception into the envelope.
     */
    public static function fromException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            // The status is read off the exception, not assumed: a 422 is the
            // norm but ThrottlesSignIn-style refusals may carry another.
            return self::response(
                'validation_failed',
                $e->status,
                null,
                ['fields' => $e->errors()],
            );
        }

        if ($e instanceof ThrottleRequestsException) {
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            // The header travels as well as the meta. `Retry-After` is the
            // standard signal every HTTP client already understands, and
            // rebuilding the response from scratch would otherwise drop the
            // one piece of information a backing-off client needs.
            return self::withHeaders(
                self::response(
                    'rate_limited',
                    429,
                    null,
                    $retryAfter === null ? [] : ['retry_after_seconds' => (int) $retryAfter],
                ),
                $e->getHeaders(),
            );
        }

        if ($e instanceof AuthenticationException) {
            return self::response('unauthenticated', 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::response('forbidden', 403);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return self::response('not_found', 404);
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return self::response('method_not_allowed', 405);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return self::withHeaders(
                self::response(
                    self::codeForStatus($status),
                    $status,
                    self::proseOrNull($e->getMessage()),
                ),
                $e->getHeaders(),
            );
        }

        // Anything unmapped is a bug, not a message to the user. Never leak
        // an internal exception string to a phone.
        return self::response('server_error', 500);
    }

    /**
     * Rewrite an ALREADY-RENDERED error response into the envelope.
     *
     * Middleware that refuses a request returns a response rather than
     * throwing — EnsureMerchantPermission's `permission_required` is the live
     * example, and it is shared with the panels so it cannot simply be
     * changed. This is the catch-all that keeps those in one shape too.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, int $status): JsonResponse
    {
        // Already ours — a middleware that built the envelope itself.
        if (isset($payload['error']['code'])) {
            return new JsonResponse($payload, $status);
        }

        $code = is_string($payload['code'] ?? null)
            ? $payload['code']
            : self::codeForStatus($status);

        $meta = [];

        if (is_array($payload['errors'] ?? null)) {
            $meta['fields'] = $payload['errors'];
        }

        // Carry through anything the refusal added beyond the standard keys —
        // EnsureMerchantPermission's `permission` slug, for instance, which
        // is how the app can say WHICH permission is missing.
        foreach ($payload as $key => $value) {
            if (! in_array($key, ['code', 'message', 'errors', 'error'], true)) {
                $meta[$key] = $value;
            }
        }

        return self::response(
            $code,
            $status,
            self::proseOrNull(is_string($payload['message'] ?? null) ? $payload['message'] : null),
            $meta,
        );
    }

    /**
     * Carry an exception's own headers onto the enveloped response.
     *
     * Values are cast to string: ThrottleRequestsException carries
     * Retry-After and the X-RateLimit-* family as INTEGERS, and Symfony's
     * ResponseHeaderBag::set() accepts array|string|null only — without the
     * cast every real mobile 429 became a 500 in the very act of being
     * enveloped.
     *
     * @param  array<string, mixed>  $headers
     */
    private static function withHeaders(JsonResponse $response, array $headers): JsonResponse
    {
        foreach ($headers as $header => $value) {
            $response->headers->set($header, is_array($value) ? $value : (string) $value);
        }

        return $response;
    }

    private static function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 405 => 'method_not_allowed',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_failed',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            default => 'request_failed',
        };
    }

    /**
     * Prose passes through as the fallback sentence; a bare code does not.
     *
     * The codebase deliberately throws things like
     * `ValidationException::withMessages(['code' => 'otp_attempts_exceeded'])`,
     * where the "message" IS the code. Echoing that as the human-readable
     * half would put snake_case on a customer's screen — the exact thing the
     * fallback exists to prevent — so anything that looks like an identifier
     * is dropped and the catalogue sentence is used instead.
     */
    private static function proseOrNull(?string $message): ?string
    {
        $message = trim((string) $message);

        if ($message === '' || preg_match('/^[a-z0-9_.:-]+$/', $message) === 1) {
            return null;
        }

        return $message;
    }
}
