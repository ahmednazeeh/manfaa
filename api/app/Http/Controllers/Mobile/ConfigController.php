<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\CacheableJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the apps read on launch, before anybody signs in.
 *
 * Unauthenticated by necessity: the most important thing here is the version
 * gate, and a build old enough to need blocking must be told so BEFORE it
 * tries to authenticate — quite possibly with a token shape it no longer
 * understands.
 *
 * The gate exists because an installed app cannot be recalled. If a build
 * ships that credits the wrong rate or stores its token badly, raising
 * `minimum_build` is the only lever that stops it talking to this API, and a
 * lever added after the fact cannot reach the builds already out there. It
 * ships before the first release or it is worthless.
 *
 * `server_time` is here for the till: a tablet with a wrong clock would
 * otherwise date a sale into or out of its refund window (M5), and the app
 * can measure its own drift against this on launch.
 */
final class ConfigController extends Controller
{
    /**
     * How long a client may reuse this without asking again. Short, because
     * the version gate is an emergency lever and an hour of staleness is an
     * hour of a bad build still talking to the API.
     */
    private const int CACHE_SECONDS = 300;

    public function __invoke(Request $request): Response
    {
        $response = response()->json([
            'data' => [
                'currency' => 'MVR',
                'apps' => [
                    'customer' => config('mobile.customer'),
                    'merchant' => config('mobile.merchant'),
                ],
                // Server-owned feature switches, so an app can hide a screen
                // the API will refuse. `customer_claims` is off by decision
                // (config/features.php, 2026-08-14): customers contact the
                // store, which credits the missed sale.
                'features' => [
                    'customer_claims' => (bool) config('features.customer_claims'),
                ],
            ],
        ]);

        $cached = CacheableJson::respond($request, $response, self::CACHE_SECONDS);

        // OUTSIDE the hashed body, deliberately. A second-resolution clock
        // inside it changes the ETag on every single read, so the 304 this
        // endpoint exists to serve could never once fire — the two tests
        // that appeared to prove otherwise only passed because they froze
        // time around the pair of requests. As a header it still reaches the
        // client on both the 200 and the 304.
        $cached->headers->set('X-Server-Time', CarbonImmutable::now('UTC')->toIso8601ZuluString());

        return $cached;
    }
}
