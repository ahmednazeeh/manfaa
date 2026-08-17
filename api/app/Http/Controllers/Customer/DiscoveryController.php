<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Discovery\DiscoveryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\CacheableJson;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public merchant discovery (§10 apps/web) — no auth, throttled per IP,
 * dataset cached 60s in DiscoveryService. The response carries no customer
 * data and no internal ids beyond the merchant slug.
 *
 * CONDITIONAL (M3). These are the largest payloads either app or the
 * storefront fetches, and until now every load re-downloaded the whole thing
 * even when nothing had changed. An ETag turns an unchanged shelf into a
 * ~200-byte 304. Safe by construction: a client can only send an
 * If-None-Match it was given, which means it already holds that body.
 */
class DiscoveryController extends Controller
{
    /**
     * The landing payload: the featured / increased / nearby / in_store /
     * online / recently_added shelves (identical entry shape, each capped at
     * SECTION_LIMIT) plus `categories`, the curated category rail — the
     * store categories at least one listed merchant carries, in the
     * admin's own sort order, each with its en+dv name and merchant count.
     */
    public function index(Request $request, DiscoveryService $discovery): Response
    {
        $validated = $request->validate([
            // Coordinates travel as a pair or not at all.
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);

        $lat = isset($validated['lat']) ? (float) $validated['lat'] : null;
        $lng = isset($validated['lng']) ? (float) $validated['lng'] : null;

        return CacheableJson::respond($request, response()->json([
            'data' => $discovery->sections($lat, $lng),
        ]));
    }

    /**
     * Public storefront directory: paginated, alphabetical, filterable by
     * name search and category. `q` arrives trimmed (TrimStrings) and empty
     * strings arrive as null (ConvertEmptyStringsToNull), so the 2..40 length
     * rule applies to the trimmed needle.
     */
    public function directory(Request $request, DiscoveryService $discovery): Response
    {
        // Laravel's `string` rule accepts byte strings that are not valid
        // UTF-8 (e.g. a raw %C3%28 in the query string); passed through to
        // the ILIKE binding, Postgres aborts with SQLSTATE 22021 and the
        // public endpoint answers 500. Reject the encoding here instead.
        $validUtf8 = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail('validation.regex')->translate();
            }
        };

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', $validUtf8, 'min:2', 'max:40'],
            'category' => ['sometimes', 'nullable', 'string', $validUtf8, 'max:80'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.DiscoveryService::DIRECTORY_MAX_PER_PAGE],
            // The page ceiling keeps (page-1)*per_page inside int range —
            // unbounded pages overflowed to float and crashed array_slice.
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.DiscoveryService::DIRECTORY_MAX_PAGE],
        ]);

        $result = $discovery->directory(
            $validated['q'] ?? null,
            $validated['category'] ?? null,
            (int) ($validated['per_page'] ?? DiscoveryService::DIRECTORY_DEFAULT_PER_PAGE),
            (int) ($validated['page'] ?? 1),
        );

        return CacheableJson::respond($request, response()->json([
            'data' => $result['merchants'],
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'categories' => $result['categories'],
            ],
        ]));
    }

    /**
     * Public store page. Unknown, suspended, closed and offer-less slugs all
     * take the identical abort(404) path — the response must never reveal
     * that a merchant exists but is not active.
     */
    public function show(Request $request, string $slug, DiscoveryService $discovery): Response
    {
        $store = $discovery->store($slug);

        if ($store === null) {
            abort(404);
        }

        return CacheableJson::respond($request, response()->json(['data' => $store]));
    }
}
