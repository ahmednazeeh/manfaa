<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Customers\CustomerAvatar;
use App\Models\Customer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONE way a customer profile picture is ever read.
 *
 * No auth middleware and no session check ON PURPOSE — the {file} segment
 * IS the authorisation. It is the uuid filename minted at upload
 * (CustomerAvatar): 122 random bits handed only to the account's own
 * clients, unguessable by construction, and invalidated the moment the
 * picture is replaced or removed. That is what lets the same URL render as
 * a plain <img> on the website AND through the app's image loader — which
 * sends no bearer token — without threading auth through either.
 *
 * The customer id in the path is not a secret (it already appears in the
 * account's own payloads); it only makes the lookup indexed instead of a
 * filename scan. A wrong or stale {file} for a real id answers the same
 * 404 an unknown id does.
 *
 * Cache: public + immutable for a year. Safe because a URL is never reused
 * — every upload is a new uuid, so a replaced picture is a new URL and the
 * old one 404s rather than serving stale bytes.
 */
class CustomerAvatarController extends Controller
{
    public function __invoke(int $id, string $file): Response
    {
        $customer = Customer::query()->find($id, ['id', 'avatar_path']);

        $path = $customer?->avatar_path;

        if ($path === null || $path === '' || ! hash_equals(basename($path), $file)) {
            abort(404);
        }

        $disk = Storage::disk(CustomerAvatar::DISK);

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, headers: [
            'Content-Type' => CustomerAvatar::mime($path),
            // The upload validator proved these bytes are a raster image;
            // never let a browser sniff them into anything executable
            // served from our own origin.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
