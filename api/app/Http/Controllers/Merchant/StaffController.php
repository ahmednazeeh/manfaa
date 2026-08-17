<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\MerchantSettings\RoleException;
use App\Domain\MerchantSettings\StaffException;
use App\Domain\MerchantSettings\StaffService;
use App\Domain\Mobile\MobileTokenService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantStaffResource;
use App\Models\Merchant;
use App\Models\MerchantRole;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Management of merchant panel accounts — `staff.view` to read, and
 * `staff.invite` / `staff.edit` to write. No DELETE — deactivation is the
 * only removal. The generated temporary password is returned exactly once —
 * on creation, or on an MR8 password reset — and never again; every {id}
 * resolves through the authenticated merchant's own users relation.
 *
 * The role is a `merchant_role_id`, and it is validated against the
 * MERCHANT'S OWN roles: ids are shared sequential integers, so
 * `exists:merchant_roles,id` alone would let a store attach its staff to
 * another store's role. A role belonging to someone else is refused exactly
 * as a role that does not exist — the difference is not this caller's to
 * learn.
 *
 * Which of its own roles a user may hand out is RoleService's question
 * (D5), asked inside StaffService so both write paths get the same answer.
 */
class StaffController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return MerchantStaffResource::collection(
            $this->merchant($request)->users()->with('role')->orderBy('id')->get(),
        );
    }

    public function store(Request $request, StaffService $service): JsonResponse
    {
        $merchant = $this->merchant($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('merchant_users', 'email')],
            // Required: with a per-store role table there is no tier left to
            // default to, and an invite that quietly picked a role would be
            // granting authority nobody chose.
            'merchant_role_id' => [
                'required',
                'integer',
                Rule::exists('merchant_roles', 'id')->where('merchant_id', $merchant->id),
            ],
        ]);

        try {
            [$user, $tempPassword] = $service->create(
                $merchant,
                $this->actor($request),
                $validated['name'],
                $validated['email'],
                $this->role($merchant, (int) $validated['merchant_role_id']),
            );
        } catch (RoleException $e) {
            return new JsonResponse($e->payload(), $e->status);
        }

        return new JsonResponse([
            'data' => (new MerchantStaffResource($user->loadMissing('role')))->resolve($request),
            // Shown once; the hash is all that survives.
            'temp_password' => $tempPassword,
        ], 201);
    }

    public function update(Request $request, int $id, StaffService $service): MerchantStaffResource|JsonResponse
    {
        $merchant = $this->merchant($request);

        /** @var MerchantUser $target */
        $target = $merchant->users()->findOrFail($id);

        $validated = $request->validate([
            'merchant_role_id' => [
                'sometimes',
                'integer',
                Rule::exists('merchant_roles', 'id')->where('merchant_id', $merchant->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            // MR8 employee-management completeness: the identity details.
            // The email keeps the invite's global-unique rule, ignoring only
            // the target's own row — a duplicate is refused as cleanly as a
            // duplicate invite.
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('merchant_users', 'email')->ignore($target->id)],
        ]);

        try {
            $service->update(
                $target,
                $this->actor($request),
                array_key_exists('merchant_role_id', $validated)
                    ? $this->role($merchant, (int) $validated['merchant_role_id'])
                    : null,
                array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
                array_key_exists('name', $validated) ? (string) $validated['name'] : null,
                array_key_exists('email', $validated) ? (string) $validated['email'] : null,
            );
        } catch (RoleException $e) {
            return new JsonResponse($e->payload(), $e->status);
        } catch (StaffException $e) {
            abort(422, $e->getMessage());
        }

        return new MerchantStaffResource($target->refresh()->loadMissing('role'));
    }

    /**
     * Sets a fresh server-generated temporary password on one of the
     * merchant's OWN accounts and returns it EXACTLY ONCE, at the root
     * beside `data` exactly as the invite does — only the hash survives
     * (MR8; the merchant-side twin of the superadmin reset in
     * Admin\MerchantStaffController, same generator, same shape).
     *
     * Everything the old password unlocked dies with it: panel sessions
     * (the stored session password-hash no longer matches, so
     * AuthenticateMultiGuardSession signs every live session out on its
     * next request), every mobile token (deleted outright — the app on a
     * phone in unknown hands must not stay signed in), and the remember-me
     * cookie (token rotated).
     *
     * Resetting YOURSELF is deliberately allowed: a reset is not a
     * deactivation, so neither the self guard nor the last-owner guard has
     * any business here. The target only has to be this merchant's own —
     * a foreign id is a 404 through the users relation, as everywhere.
     */
    public function resetPassword(Request $request, int $id, MobileTokenService $mobileTokens): JsonResponse
    {
        /** @var MerchantUser $target */
        $target = $this->merchant($request)->users()->findOrFail($id);

        $tempPassword = Str::password(20);

        // The 'hashed' cast stores the hash; the plaintext lives only in
        // this response.
        $target->password = $tempPassword;
        $target->setRememberToken(Str::random(60));
        $target->save();

        $mobileTokens->revokeEverything($target);

        return new JsonResponse([
            'data' => (new MerchantStaffResource($target->loadMissing('role')))->resolve($request),
            // Shown once; the hash is all that survives.
            'temp_password' => $tempPassword,
        ]);
    }

    /**
     * The role, scoped to the merchant a second time. The validation rule
     * above has already refused a foreign id; this is the query that makes
     * the tenancy structural rather than a rule somebody could drop.
     */
    private function role(Merchant $merchant, int $id): MerchantRole
    {
        /** @var MerchantRole $role */
        $role = MerchantRole::query()
            ->where('merchant_id', $merchant->id)
            ->findOrFail($id);

        return $role;
    }

    private function actor(Request $request): MerchantUser
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user;
    }

    private function merchant(Request $request): Merchant
    {
        return $this->actor($request)->merchant;
    }
}
