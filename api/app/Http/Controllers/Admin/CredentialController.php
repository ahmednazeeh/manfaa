<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Credentials\CredentialAlreadyRevokedException;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\VendorAbility;
use App\Http\Controllers\Controller;
use App\Http\Resources\CredentialResource;
use App\Models\ApiCredential;
use App\Models\Merchant;
use App\Models\PosVendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Admin issuance and revocation of vendor credentials (§9.1). Our team does
 * the POS integrations, so credentials are admin-issued: the plaintext token
 * appears exactly once, in the 201 body, and is handed to the vendor
 * out-of-band. Revocation is per-credential — by id, across any merchant —
 * and audited via issued_by / revoked_by / revoked_at on the credential row.
 */
class CredentialController extends Controller
{
    public function store(Request $request, Merchant $merchant, CredentialService $credentials): JsonResponse
    {
        $validated = $request->validate([
            'pos_vendor_id' => ['required', 'integer', 'exists:pos_vendors,id'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', Rule::in(VendorAbility::values())],
        ]);

        $vendor = PosVendor::query()->findOrFail($validated['pos_vendor_id']);

        $issued = $credentials->issue($merchant, $vendor, $validated['abilities'], $request->user('admin'));

        return response()->json([
            // Shown once. We store only the SHA-256 digest — losing this
            // string means issuing a new credential, never recovering it.
            'plaintext_token' => $issued->plainTextToken,
            'credential' => new CredentialResource($issued->credential->load('posVendor')),
        ], 201);
    }

    public function index(Merchant $merchant): AnonymousResourceCollection
    {
        $rows = ApiCredential::query()
            ->where('merchant_id', $merchant->getKey())
            ->with('posVendor')
            ->orderByDesc('id')
            ->get();

        return CredentialResource::collection($rows);
    }

    public function destroy(Request $request, ApiCredential $credential, CredentialService $credentials): JsonResponse
    {
        try {
            $credential = $credentials->revoke($credential, $request->user('admin'));
        } catch (CredentialAlreadyRevokedException $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json([
            'data' => new CredentialResource($credential->load('posVendor')),
        ]);
    }
}
