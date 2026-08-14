<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReconciliationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    /**
     * The latest reconciliation runs, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $runs = ReconciliationRun::query()
            ->orderByDesc('ran_at')
            ->orderByDesc('id')
            ->limit((int) ($validated['limit'] ?? 30))
            ->get();

        return response()->json([
            'data' => $runs->map(fn (ReconciliationRun $run) => [
                'id' => $run->id,
                'ran_at' => $run->ran_at->toIso8601String(),
                'status' => $run->status,
                'journals_checked' => $run->journals_checked,
                'issues' => $run->issues,
                'totals' => $run->totals,
            ])->values(),
        ]);
    }
}
