<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Platform\BrandAsset;
use App\Models\PlatformBrandAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a brand mark. PUBLIC, and it ALWAYS answers with an image.
 *
 * That guarantee is the whole design. Because /api/brand/{slot} can be
 * relied on to render, apps/web, apps/merchant and apps/admin point an <img>
 * straight at it — no "has a logo been uploaded?" query, no fallback branch
 * repeated in three codebases, no flash of one mark replaced by another.
 *
 * Freshness is an ETag over the bytes actually served. A superadmin's upload
 * changes the ETag, so the next revalidation (a 304 when nothing changed)
 * picks it up within one request — without any cache-busting token, which a
 * built frontend could not know anyway.
 */
class BrandAssetController extends Controller
{
    public function __invoke(Request $request, string $slot): Response
    {
        abort_unless(BrandAsset::isSlot($slot), 404);

        $asset = PlatformBrandAsset::query()->where('slot', $slot)->first();
        $disk = Storage::disk(BrandAsset::DISK);

        if ($asset !== null && $asset->path !== '' && $disk->exists($asset->path)) {
            $bytes = $disk->get($asset->path);
            $mime = BrandAsset::mime($asset->path);
        } else {
            // No upload, or a row pointing at a file that is gone. Either
            // way the surface gets a logo rather than a broken image.
            $default = BrandAsset::defaultPath($slot);
            abort_unless(is_file($default), 404);
            $bytes = (string) file_get_contents($default);
            $mime = BrandAsset::mime($default);
        }

        $etag = '"'.substr(sha1((string) $bytes), 0, 20).'"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'ETag' => $etag,
            // Revalidate every time: a logo is small, a 304 is cheap, and a
            // stale brand across every surface is not.
            'Cache-Control' => 'public, no-cache, must-revalidate',
            // The bytes are an image and nothing else, whatever the
            // extension claims.
            'X-Content-Type-Options' => 'nosniff',
            // Belt and braces for the packaged SVGs: even served from our
            // origin, nothing in them may execute or fetch.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
