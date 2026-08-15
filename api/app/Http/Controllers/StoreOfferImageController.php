<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Storefront\OfferImage;
use App\Models\StoreOffer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a featured-offer banner. Public and unauthenticated: a banner on
 * the storefront is the least secret thing on the platform.
 *
 * An offer that is inactive, scheduled or ended still serves its image. The
 * storefront simply does not reference it, and 404-ing here would break the
 * admin screen that is showing an admin the very banner they are deciding
 * whether to run.
 */
class StoreOfferImageController extends Controller
{
    public function __invoke(int $id): StreamedResponse
    {
        $offer = StoreOffer::query()->whereKey($id)->first(['id', 'image_path']);

        if ($offer === null || $offer->image_path === null || $offer->image_path === '') {
            abort(404);
        }

        $disk = Storage::disk(OfferImage::DISK);

        if (! $disk->exists($offer->image_path)) {
            abort(404);
        }

        return $disk->response($offer->image_path, headers: [
            'Content-Type' => OfferImage::mime($offer->image_path),
            // The validator already proved these bytes are a raster image;
            // nosniff stops a browser reinterpreting them as anything
            // executable served from our own origin.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            // The URL carries a content-derived `v`, so a replaced banner is
            // a different URL and this one can never go stale.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
