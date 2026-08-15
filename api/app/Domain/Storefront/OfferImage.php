<?php

declare(strict_types=1);

namespace App\Domain\Storefront;

/**
 * Where a featured-offer banner image lives and how it is addressed.
 *
 * Public, like a category icon and for the same reason — a banner on the
 * storefront is the least secret thing on the platform — and served through
 * a controller rather than a public disk so one route works on every app
 * host without a second nginx location block.
 *
 * Path: offers/{id}/{uuid}.{ext}. The uuid means a replaced banner is a new
 * URL, so the response can be cached hard and never goes stale.
 *
 * SVG is refused here exactly as it is for icons: an SVG is a document that
 * may carry script, and this one would be served from our own origin.
 */
final class OfferImage
{
    public const string DISK = 'offer-images';

    /**
     * Upload ceiling in kilobytes. Larger than an icon because this is
     * artwork a phone shows nearly full-width, small enough that a banner
     * cannot become the heaviest thing on the page.
     */
    public const int MAX_KB = 2048;

    public static function url(int $offerId, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return url(sprintf('/api/store-offers/%d/image?v=%s', $offerId, self::version($path)));
    }

    /** Short content-address of the stored path — the cache-busting token. */
    public static function version(string $path): string
    {
        return substr(sha1($path), 0, 12);
    }

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
