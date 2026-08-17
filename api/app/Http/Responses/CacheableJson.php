<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Conditional GET for the mobile tree.
 *
 * A phone on a Maldives mobile network pays for every byte and every
 * round trip. An unchanged payload re-sent in full on each app launch is the
 * cheapest waste on the platform to remove: the client keeps the ETag, sends
 * it back as If-None-Match, and an unchanged answer costs a ~200-byte 304
 * instead of the whole body.
 *
 * The ETag is derived from the rendered content, so it changes exactly when
 * the answer does and never when it does not — no cache key to invent and
 * nothing to remember to invalidate.
 */
final class CacheableJson
{
    /**
     * @param  int  $maxAge  seconds a client may reuse the body without asking
     */
    public static function respond(Request $request, JsonResponse $response, int $maxAge = 0): SymfonyResponse
    {
        // Weak validator: two responses with equal content are equivalent for
        // the client's purposes even though the bytes were generated twice.
        $etag = 'W/"'.sha1((string) $response->getContent()).'"';

        $response->headers->set('ETag', $etag);

        // `private` throughout: these answers are per-account. Cloudflare
        // sits in front of this origin and must never hold one customer's
        // balance for another.
        $response->headers->set(
            'Cache-Control',
            $maxAge > 0 ? "private, max-age={$maxAge}, must-revalidate" : 'private, no-cache',
        );

        if (self::matches($request->headers->get('If-None-Match'), $etag)) {
            // 304 carries no body by definition; the validators come with it
            // so the client can keep revalidating cheaply.
            // headers->set, NOT setEtag(): Symfony's setEtag() re-quotes any
            // value that does not begin with a double quote, so a weak tag
            // W/"abc" goes out of the 304 as "W/"abc"" — a different string,
            // and not a legal entity-tag. A client that refreshes its stored
            // headers from the 304 (RFC 9111 §4.3.4 — browsers, OkHttp,
            // NSURLCache) then sends the mangled value back, it never
            // matches, and the full body is returned on every other request.
            $notModified = new Response('', SymfonyResponse::HTTP_NOT_MODIFIED);
            $notModified->headers->set('ETag', $etag);
            $notModified->headers->set('Cache-Control', (string) $response->headers->get('Cache-Control'));

            return $notModified;
        }

        return $response;
    }

    /**
     * If-None-Match is a LIST, and a client is entitled to send several tags
     * or the wildcard. Comparing the raw header against one tag would miss a
     * legitimate match and silently re-send the whole body every time.
     */
    private static function matches(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        if (trim($header) === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }
}
