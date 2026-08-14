<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PosVendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POS vendor registry (§5, §9.1). Vendors are the integration counterparties
 * we onboard ourselves; credentials link a merchant to one of them.
 */
class PosVendorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
        ]);

        // refresh() picks up the DB-defaulted integration_status.
        $vendor = PosVendor::query()->create($validated)->refresh();

        return response()->json(['data' => $this->serialize($vendor)], 201);
    }

    public function index(): JsonResponse
    {
        $vendors = PosVendor::query()
            ->withCount([
                'apiCredentials as active_credentials_count' => fn ($query) => $query->whereNull('revoked_at'),
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $vendors->map(fn (PosVendor $vendor) => $this->serialize($vendor) + [
                'active_credentials_count' => (int) $vendor->active_credentials_count,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PosVendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'contact' => $vendor->contact,
            'integration_status' => $vendor->integration_status,
        ];
    }
}
