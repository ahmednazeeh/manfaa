<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

/**
 * Where a store logo lives and how it is addressed.
 *
 * **Decision (2026-08-15): every logo is served through the authorising
 * controller — there is no public copy of any logo, ever.**
 *
 * The alternative — private storage before approval, republished to the
 * public disk on activation — was rejected as the more complex of the two
 * correct options. It needs a publish step wired into `approve()`, an
 * unpublish step for whatever the reverse transitions turn out to be, and
 * it leaves the file's location and the merchant's status as two facts that
 * can disagree after any transition we forget. Serving everything through
 * one route keeps a single storage location and derives visibility from the
 * merchant row at request time, which is the only thing that can be right
 * by construction:
 *
 *   status === 'active'  → anyone may fetch it (the store is listed)
 *   anything else        → the store's own users, or an admin
 *
 * The cost is that logo bytes stream through PHP instead of nginx. That is
 * bounded: the response is immutable-cacheable (the URL carries a
 * content-derived `v`, so a replaced logo is a different URL), an unchanged
 * logo answers 304 from the browser cache, and the catalogue is small.
 *
 * Path: merchants/{id}/{uuid}.{ext}. The uuid means a replaced logo never
 * overwrites its predecessor's URL in a cache, and the raw path is never
 * guessable even if the disk were somehow exposed.
 */
final class MerchantLogo
{
    public const string DISK = 'logos';

    /**
     * The addressable URL for a stored logo, or null when the store has
     * none. `v` is derived from the stored path (which changes on every
     * upload — the filename is a uuid), so a replaced logo is a new URL and
     * the old one can be cached hard without ever going stale.
     *
     * Built with url(), i.e. against the CURRENT request's host rather than
     * a fixed one, and that is load-bearing: every app host serves /api/*
     * from this Laravel origin (nginx snippet manfaa-laravel-api.conf), so
     * an admin reviewing a pending store gets an admin.manfaa.app URL, the
     * merchant panel a merchant.manfaa.app one — SAME-ORIGIN, so the
     * browser sends the session cookie with the <img> request and the
     * authorising controller can recognise the caller. A fixed api.manfaa.app
     * base would make every logo request cross-origin and therefore
     * anonymous, and every pre-approval logo would 404 in both panels.
     * (Public logos need no cookie, so cross-origin is harmless for them —
     * which is why a URL cached by one host still renders on another.)
     */
    public static function url(string $slug, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return url(sprintf('/api/merchants/%s/logo?v=%s', $slug, self::version($path)));
    }

    /** Short content-address of the stored path — the cache-busting token. */
    public static function version(string $path): string
    {
        return substr(sha1($path), 0, 12);
    }

    /**
     * Response Content-Type for a stored logo, derived from the extension we
     * chose at upload (the validator already established the bytes are a
     * raster image of that type — the client's claimed Content-Type is never
     * echoed back).
     */
    public static function mime(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
