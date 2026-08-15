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

    /**
     * The one artwork shape: 16:9, 1200×675 the size to supply.
     *
     * An image banner IS the card — the storefront prints nothing over it —
     * so the uploaded picture and the slot it lands in are the same shape
     * and the artwork is shown whole. One fixed ratio is the difference
     * between an admin who knows what to hand a designer and one who
     * uploads whatever came back and watches the storefront eat its edges.
     */
    public const int TARGET_WIDTH = 1200;

    public const int TARGET_HEIGHT = 675;

    /** How the shape is said out loud, wherever it is said. */
    public const string RATIO_LABEL = '16:9';

    /** Smallest supply that still stays sharp on a 2x phone. */
    public const int MIN_WIDTH = 800;

    public const int MIN_HEIGHT = 450;

    /** A print-sized original is a page-weight problem, not a quality win. */
    public const int MAX_WIDTH = 3200;

    public const int MAX_HEIGHT = 1800;

    /**
     * How far from 16:9 an upload may sit. Wide enough that an export
     * rounded to whole pixels (1200×676) passes, narrow enough that 16:10
     * and 2:1 — the shapes that actually arrive by mistake — do not.
     */
    public const float RATIO_TOLERANCE = 0.06;

    public static function ratio(): float
    {
        return self::TARGET_WIDTH / self::TARGET_HEIGHT;
    }

    /** Is this upload the offer-artwork shape? */
    public static function ratioAccepted(int $width, int $height): bool
    {
        if ($height <= 0) {
            return false;
        }

        return abs($width / $height - self::ratio()) <= self::RATIO_TOLERANCE;
    }

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
