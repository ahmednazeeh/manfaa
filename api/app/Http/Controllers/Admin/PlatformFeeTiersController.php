<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Platform\InvalidTierScheduleException;
use App\Domain\Platform\TierScheduleService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformFeeTierScheduleResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The §4 fee tier table, admin-manageable with append-only effective dating.
 * GET returns the schedule active now plus the full history; POST publishes
 * a new future-dated schedule (validated tier table, >= 1h lead time,
 * created_by audited). Nothing is ever updated or deleted.
 */
class PlatformFeeTiersController extends Controller
{
    public function index(TierScheduleService $schedules): JsonResponse
    {
        $current = $schedules->current();

        return response()->json([
            'data' => [
                'current' => $current === null ? null : (new PlatformFeeTierScheduleResource($current))->resolve(),
                'history' => PlatformFeeTierScheduleResource::collection($schedules->history())->resolve(),
            ],
        ]);
    }

    public function store(Request $request, TierScheduleService $schedules): JsonResponse
    {
        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.from_bp' => ['required', 'integer'],
            'tiers.*.to_bp' => ['required', 'integer'],
            'tiers.*.fee_bp' => ['required', 'integer'],
        ]);

        try {
            $schedule = $schedules->create(
                array_map(
                    fn (array $tier): array => [
                        'from_bp' => (int) $tier['from_bp'],
                        'to_bp' => (int) $tier['to_bp'],
                        'fee_bp' => (int) $tier['fee_bp'],
                    ],
                    $validated['tiers'],
                ),
                CarbonImmutable::parse($validated['effective_from']),
                $request->user('admin'),
            );
        } catch (InvalidTierScheduleException|InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return (new PlatformFeeTierScheduleResource($schedule))
            ->response($request)
            ->setStatusCode(201);
    }
}
