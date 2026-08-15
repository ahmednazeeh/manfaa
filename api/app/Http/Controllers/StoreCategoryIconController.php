<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Storefront\StoreCategoryIcon;
use App\Models\StoreCategory;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an uploaded store-category icon. Public and unauthenticated: the
 * curated category list IS the storefront's navigation, so its artwork is
 * as public as the category name beside it.
 *
 * A DEACTIVATED category still serves its icon. Deactivation removes the
 * category from the rail and from the store picker; it is not a secret, and
 * a 404 here would only break the admin screen that is showing the very
 * icon an admin is deciding whether to keep.
 */
class StoreCategoryIconController extends Controller
{
    public function __invoke(string $slug): StreamedResponse
    {
        $category = StoreCategory::query()
            ->where('slug', $slug)
            ->first(['id', 'slug', 'icon_path']);

        if ($category === null || $category->icon_path === null || $category->icon_path === '') {
            abort(404);
        }

        $disk = Storage::disk(StoreCategoryIcon::DISK);

        if (! $disk->exists($category->icon_path)) {
            abort(404);
        }

        return $disk->response($category->icon_path, headers: [
            'Content-Type' => StoreCategoryIcon::mime($category->icon_path),
            // The upload validator already proved these bytes are a raster
            // image; nosniff stops a browser reinterpreting them as anything
            // executable served from our own origin.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            // The URL carries a content-derived `v`, so a replaced icon is a
            // different URL and this one can never go stale.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
