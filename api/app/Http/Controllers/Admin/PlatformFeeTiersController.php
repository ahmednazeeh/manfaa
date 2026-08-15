<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Money\Percent;
use App\Domain\Platform\InvalidTierScheduleException;
use App\Domain\Platform\TierScheduleService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformFeeTierScheduleResource;
use App\Rules\PercentRate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The §4 fee tier table, admin-manageable with append-only effective dating.
 * GET returns the schedule active now plus the full history; POST publishes
 * a new future-dated schedule (validated tier table, >= 1h lead time,
 * created_by audited). Nothing is ever updated or deleted.
 *
 * WIRE FORMAT (PLAN §1): bands are submitted and returned as 2-decimal
 * percent strings — {from_percent, to_percent, fee_percent}. They are
 * converted to integer basis points here, once, and the schedule is stored
 * and evaluated in basis points exactly as before.
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
            'tiers.*.from_percent' => ['required', PercentRate::cashback()],
            'tiers.*.to_percent' => ['required', PercentRate::cashback()],
            'tiers.*.fee_percent' => ['required', PercentRate::fee()],
        ]);

        try {
            $schedule = $schedules->create(
                array_map(
                    fn (array $tier): array => [
                        'from_bp' => Percent::toBasisPoints($tier['from_percent']),
                        'to_bp' => Percent::toBasisPoints($tier['to_percent']),
                        'fee_bp' => Percent::toFeeBasisPoints($tier['fee_percent']),
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
