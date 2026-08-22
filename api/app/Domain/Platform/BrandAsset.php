<?php

declare(strict_types=1);

namespace App\Domain\Platform;

/**
 * The platform's own brand marks, replaceable by a superadmin.
 *
 * FIVE slots and no more. Every website surface draws from these, so a new
 * logo is one upload rather than a hunt through three codebases — which is
 * exactly what it used to be: `default-logo.svg`, `default-logo-dark.svg`
 * and `mini-logo.svg` were committed files in apps/web and apps/merchant,
 * duplicated between them, changeable only by a deploy.
 *
 * Each slot answers at a STABLE url — /api/brand/{slot} — that always
 * returns an image: the uploaded one when a superadmin has set it, the
 * packaged default otherwise. That is what lets the frontends point an
 * <img> at it unconditionally, with no fallback logic, no loading state and
 * no flash of the wrong mark. The apps do not know or care whether a brand
 * has been uploaded.
 *
 * Freshness is an ETag, not an expiry. A logo is small, revalidation costs a
 * 304, and the alternative — a cache-busting query the built apps cannot
 * know — would mean a new logo appearing only after a rebuild of all three.
 */
final class BrandAsset
{
    public const string DISK = 'brand';

    /** A wordmark is not a photograph. */
    public const int MAX_KB = 1024;

    /**
     * slot => [label, whether it must be square-ish].
     *
     * @var array<string, array{label: string, shape: string}>
     */
    public const array SLOTS = [
        'landscape_light' => ['label' => 'Landscape logo — light backgrounds', 'shape' => 'landscape'],
        'landscape_dark' => ['label' => 'Landscape logo — dark backgrounds', 'shape' => 'landscape'],
        'square_light' => ['label' => 'Square logo — light backgrounds', 'shape' => 'square'],
        'square_dark' => ['label' => 'Square logo — dark backgrounds', 'shape' => 'square'],
        'favicon' => ['label' => 'Favicon', 'shape' => 'favicon'],
    ];

    /** @return list<string> */
    public static function slots(): array
    {
        return array_keys(self::SLOTS);
    }

    public static function isSlot(string $slot): bool
    {
        return array_key_exists($slot, self::SLOTS);
    }

    /**
     * The public url for a slot. Constant across uploads on purpose — see
     * the class note on why freshness is an ETag rather than a query.
     *
     * Built with url(), so it resolves against the CURRENT host: every app
     * host serves /api/* from this same Laravel origin, so manfaa.app gets a
     * manfaa.app url and the merchant panel a merchant.manfaa.app one, and
     * neither is ever a cross-origin request.
     */
    public static function url(string $slot): string
    {
        return url('/api/brand/'.$slot);
    }

    /** Where the packaged fallback for a slot lives. */
    public static function defaultPath(string $slot): string
    {
        return base_path('resources/brand/'.($slot === 'favicon' ? 'favicon.ico' : $slot.'.svg'));
    }

    /**
     * Response Content-Type, from the extension chosen at upload — never
     * from the client's claimed type.
     */
    public static function mime(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            // Only the PACKAGED defaults are svg. An uploaded svg is refused
            // at the door: it is a document that may carry script, and it
            // would be served from our own origin on every surface we have —
            // stored XSS with the widest blast radius on the platform.
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}
