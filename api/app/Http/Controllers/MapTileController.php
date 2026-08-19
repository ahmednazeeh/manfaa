<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Map tiles, served from OUR origin.
 *
 * The merchant app's pin picker kept drawing a grey square on the owner's
 * device while tile.openstreetmap.org answered perfectly from this server —
 * an unreproducible, unloggable failure somewhere between a Maldivian
 * network and a third party we do not run. Guessing at user agents twice
 * did not fix it, so the dependency itself goes: the apps now ask
 * manfaa.app for tiles, which is the one host they have already proven they
 * can reach (every other screen works), and the fetch happens here where
 * the failure lands in OUR log instead of in a silent grey rectangle.
 *
 * Being a caching proxy is also the polite thing to do by OSM: one identified
 * server fetches each tile once and serves it to every merchant afterwards,
 * with a long public cache so Cloudflare's edge answers most of them and the
 * origin is never asked twice for the same square.
 */
class MapTileController extends Controller
{
    /** OSM's policy: identify the app, with a contact. */
    private const AGENT = 'ManfaaMaps/1.0 (+https://manfaa.app; support@manfaa.app)';

    private const DISK = 'local';

    private const CACHE_DAYS = 30;

    private const MAX_ZOOM = 19;

    public function __invoke(Request $request, int $z, int $x, int $y): Response
    {
        // Bounds first: {z}/{x}/{y} come off the wire, and an unbounded pair
        // would let anyone walk the whole planet through us.
        if ($z < 0 || $z > self::MAX_ZOOM) {
            abort(404);
        }
        $limit = 2 ** $z;
        if ($x < 0 || $y < 0 || $x >= $limit || $y >= $limit) {
            abort(404);
        }

        $path = "map-tiles/{$z}/{$x}/{$y}.png";
        $disk = Storage::disk(self::DISK);

        $fresh = $disk->exists($path)
            && $disk->lastModified($path) > now()->subDays(self::CACHE_DAYS)->getTimestamp();

        if (! $fresh) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::AGENT])
                    ->timeout(8)
                    ->retry(2, 200)
                    ->get("https://tile.openstreetmap.org/{$z}/{$x}/{$y}.png");

                if (! $response->successful()) {
                    Log::warning('Map tile upstream refused', [
                        'tile' => "{$z}/{$x}/{$y}",
                        'status' => $response->status(),
                    ]);

                    // A stale tile beats a grey square.
                    if (! $disk->exists($path)) {
                        abort(502);
                    }
                } else {
                    $disk->put($path, $response->body());
                }
            } catch (\Throwable $e) {
                Log::warning('Map tile fetch failed', [
                    'tile' => "{$z}/{$x}/{$y}",
                    'error' => $e->getMessage(),
                ]);

                if (! $disk->exists($path)) {
                    abort(502);
                }
            }
        }

        return response($disk->get($path), 200, [
            'Content-Type' => 'image/png',
            // Long and public: the edge should answer these, not us.
            'Cache-Control' => 'public, max-age=2592000, immutable',
        ]);
    }
}
