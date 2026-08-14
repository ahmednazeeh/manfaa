<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Discovery\DiscoveryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public merchant discovery (§10 apps/web) — no auth, throttled per IP,
 * dataset cached 60s in DiscoveryService. The response carries no customer
 * data and no internal ids beyond the merchant slug.
 */
class DiscoveryController extends Controller
{
    public function index(Request $request, DiscoveryService $discovery): JsonResponse
    {
        $validated = $request->validate([
            // Coordinates travel as a pair or not at all.
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);

        $lat = isset($validated['lat']) ? (float) $validated['lat'] : null;
        $lng = isset($validated['lng']) ? (float) $validated['lng'] : null;

        return response()->json([
            'data' => $discovery->sections($lat, $lng),
        ]);
    }
}
