<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\MerchantSettings\StaffException;
use App\Domain\MerchantSettings\StaffService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantStaffResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Owner management of merchant panel accounts (merchant.role:owner gates
 * the routes — a MANAGER cannot reach this surface at all). No DELETE — deactivation is the only removal. The generated
 * temporary password is returned exactly once, on creation, and never
 * again; every {id} resolves through the authenticated merchant's own
 * users relation.
 */
class StaffController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return MerchantStaffResource::collection(
            $this->merchant($request)->users()->orderBy('id')->get(),
        );
    }

    public function store(Request $request, StaffService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('merchant_users', 'email')],
            // Optional tier; omitted means the back-compatible staff invite.
            // An owner may assign any of the three (PLAN §1).
            'role' => ['sometimes', 'string', Rule::in(MerchantUser::ROLES)],
        ]);

        [$user, $tempPassword] = $service->create(
            $this->merchant($request),
            $validated['name'],
            $validated['email'],
            $validated['role'] ?? 'staff',
        );

        return new JsonResponse([
            'data' => (new MerchantStaffResource($user))->resolve($request),
            // Shown once; the hash is all that survives.
            'temp_password' => $tempPassword,
        ], 201);
    }

    public function update(Request $request, int $id, StaffService $service): MerchantStaffResource
    {
        /** @var MerchantUser $target */
        $target = $this->merchant($request)->users()->findOrFail($id);

        $validated = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(MerchantUser::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /** @var MerchantUser $actor */
        $actor = $request->user('merchant');

        try {
            $service->update(
                $target,
                $actor,
                $validated['role'] ?? null,
                array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
            );
        } catch (StaffException $e) {
            abort(422, $e->getMessage());
        }

        return new MerchantStaffResource($target->refresh());
    }

    private function merchant(Request $request): Merchant
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user->merchant;
    }
}
