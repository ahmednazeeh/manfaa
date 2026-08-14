<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin read model over every merchant's promotions (§10), filterable by
 * merchant, status, and liveness (published AND window covering now).
 */
class PromotionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'in:draft,published,ended,cancelled'],
            'live' => ['sometimes', 'boolean'],
        ]);

        $query = Promotion::query()->orderByDesc('id');

        if (isset($validated['merchant_id'])) {
            $query->where('merchant_id', (int) $validated['merchant_id']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if ($request->boolean('live')) {
            $now = CarbonImmutable::now('UTC');
            $query->where('status', 'published')
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>', $now);
        }

        $page = $query->paginate(25);

        return new JsonResponse([
            'data' => PromotionResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
