<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Zoning\ZoneAssigner;
use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Island zoning CRUD. The admin draws a polygon around an island (a closed
 * ring of dots) and names it; when the name field was left empty the ADMIN
 * CLIENT resolves one by reverse-geocoding the centroid before submitting —
 * the API always receives a real name.
 *
 * Every write reassigns pinned branches (write-time geometry) and busts the
 * discovery dataset cache, whose entries carry zone ids for the zone filter.
 */
class ZonesController extends Controller
{
    public function index(): JsonResponse
    {
        $zones = Zone::query()
            ->withCount('branches')
            // The admin-arranged order (added order until someone edits it);
            // the same order the app's island picker shows.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Zone $zone): array => [
                'id' => $zone->id,
                'name' => $zone->name,
                'name_dv' => $zone->name_dv,
                'polygon' => $zone->polygon,
                'branch_count' => $zone->branches_count,
                'sort_order' => $zone->sort_order,
            ]);

        return new JsonResponse(['data' => $zones]);
    }

    public function store(Request $request): JsonResponse
    {
        $zone = DB::transaction(function () use ($request): Zone {
            $zone = Zone::create([
                ...$this->validated($request),
                // New islands join at the END of the arranged list — adding
                // one must never shuffle an order the admin already set.
                'sort_order' => ((int) Zone::query()->max('sort_order')) + 1,
            ]);
            ZoneAssigner::reassignAll();

            return $zone;
        });

        $this->forgetDiscovery();

        return new JsonResponse(['data' => $this->present($zone)], 201);
    }

    /**
     * The whole order in one write: the ids exactly as the list should
     * read. All-or-nothing and complete — a partial order would be two
     * admins' half-arrangements interleaved.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $ids = array_map(intval(...), $validated['ids']);
        $known = Zone::query()->pluck('id')->all();

        if (count($ids) !== count($known) || array_diff($known, $ids) !== []) {
            abort(422, 'The order must list every zone exactly once.');
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                Zone::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return $this->index();
    }

    public function update(Request $request, Zone $zone): JsonResponse
    {
        DB::transaction(function () use ($request, $zone): void {
            $zone->update($this->validated($request));
            ZoneAssigner::reassignAll();
        });

        $this->forgetDiscovery();

        return new JsonResponse(['data' => $this->present($zone->refresh())]);
    }

    public function destroy(Zone $zone): JsonResponse
    {
        DB::transaction(function () use ($zone): void {
            // FK is nullOnDelete; the recompute then offers the orphaned
            // branches to the remaining zones (overlap edge).
            $zone->delete();
            ZoneAssigner::reassignAll();
        });

        $this->forgetDiscovery();

        return new JsonResponse(null, 204);
    }

    /**
     * @return array{name: string, name_dv: ?string, polygon: list<array{lat: float, lng: float}>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:100'],
            'polygon' => ['required', 'array', 'min:3', 'max:500'],
            'polygon.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'polygon.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // Keep only the two keys per point — the client may send map noise.
        $validated['polygon'] = array_values(array_map(
            fn (array $point): array => [
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
            ],
            $validated['polygon'],
        ));

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Zone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'name_dv' => $zone->name_dv,
            'polygon' => $zone->polygon,
            'branch_count' => $zone->branches()->count(),
            'sort_order' => $zone->sort_order,
        ];
    }

    private function forgetDiscovery(): void
    {
        Cache::forget(DiscoveryService::CACHE_KEY);
    }
}
