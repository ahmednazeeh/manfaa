<?php

declare(strict_types=1);

namespace App\Domain\Storefront;

/**
 * Where an uploaded store-category icon lives and how it is addressed.
 *
 * Unlike a merchant logo (App\Domain\Onboarding\MerchantLogo), which is
 * private until the store is approved and therefore served through an
 * authorising controller, a category icon is PUBLIC by definition: the
 * curated category list is the storefront's own navigation, visible to
 * every anonymous visitor. It is still served through a controller rather
 * than a public disk, for one reason — the same route then works on every
 * app host without a second nginx location block, exactly as the logo route
 * already does.
 *
 * Path: store-categories/{id}/{uuid}.{ext}. The uuid means a replaced icon
 * is a new URL, so the response can be cached hard and never goes stale.
 *
 * SVG is deliberately NOT accepted. An SVG is a document that may carry
 * script, and this one would be served from our own origin — an admin
 * uploading a hostile file would be stored XSS against every visitor to the
 * landing page. Raster only, the same set the logo upload accepts.
 */
final class StoreCategoryIcon
{
    public const string DISK = 'store-category-icons';

    /** Upload ceiling in kilobytes — a rail glyph, not a photograph. */
    public const int MAX_KB = 512;

    /**
     * The addressable URL for a stored icon, or null when the category has
     * none. `v` is derived from the stored path (a uuid that changes on
     * every upload), so a replaced icon is a different URL.
     */
    public static function url(string $slug, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return url(sprintf('/api/store-categories/%s/icon?v=%s', $slug, self::version($path)));
    }

    /** Short content-address of the stored path — the cache-busting token. */
    public static function version(string $path): string
    {
        return substr(sha1($path), 0, 12);
    }

    /**
     * Response Content-Type, derived from the extension chosen at upload —
     * never from the client's claimed Content-Type.
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
