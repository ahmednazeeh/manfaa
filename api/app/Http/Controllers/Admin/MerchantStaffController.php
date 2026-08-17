<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\MerchantSettings\StaffException;
use App\Domain\MerchantSettings\StaffService;
use App\Domain\Mobile\MobileTokenService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantStaffResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Superadmin controls over a merchant's panel accounts (EnsureSuperadmin
 * gates the routes): the password reset a locked-out store owner phones in
 * for, and the activate/deactivate switch. Every {user} resolves through
 * the route merchant's own users relation, so a cross-tenant id is a 404 —
 * the same structural tenancy the merchant-side StaffController enforces.
 *
 * No create and no role changes here on purpose: minting accounts and
 * deciding which of a store's own roles someone holds are the store's
 * delegation questions, answered in its panel by people who hold
 * staff.invite / staff.edit — not platform questions.
 */
class MerchantStaffController extends Controller
{
    /**
     * Sets a fresh server-generated temporary password on a panel account
     * and returns it EXACTLY ONCE — the admin reads it to the merchant over
     * the phone; only the hash survives.
     *
     * Everything the old password unlocked dies with it:
     *  - panel sessions: the stored session password-hash no longer matches,
     *    so AuthenticateMultiGuardSession logs every live session out on its
     *    next request (the same mechanism any password change rides);
     *  - the merchant app: every mobile token is deleted outright, exactly
     *    as deactivation does — a reset usually means the credentials (or
     *    the phone) are in unknown hands;
     *  - the remember-me cookie: the token is rotated, so it cannot re-mint
     *    a session for whoever holds the old cookie.
     */
    public function resetPassword(Request $request, Merchant $merchant, int $user, MobileTokenService $mobileTokens): JsonResponse
    {
        /** @var MerchantUser $target */
        $target = $merchant->users()->findOrFail($user);

        $tempPassword = Str::password(20);

        // The 'hashed' cast stores the hash; the plaintext lives only in
        // this response.
        $target->password = $tempPassword;
        $target->setRememberToken(Str::random(60));
        $target->save();

        $mobileTokens->revokeEverything($target);

        return response()->json([
            'data' => (new MerchantStaffResource($target->loadMissing('role')))->resolve($request),
            // Shown once; the hash is all that survives.
            'temp_password' => $tempPassword,
        ]);
    }

    /**
     * The activate/deactivate switch, through the same StaffService the
     * merchant's own staff screen uses — so the last-active-owner guard
     * holds (a store with zero active owners is locked out of its settings
     * whoever pulled the switch; suspending the MERCHANT is the superadmin
     * lever for a rogue store) and deactivation destroys the account's app
     * tokens rather than merely refusing them.
     */
    public function update(Request $request, Merchant $merchant, int $user, StaffService $staff): JsonResponse
    {
        /** @var MerchantUser $target */
        $target = $merchant->users()->findOrFail($user);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        try {
            $staff->update($target, $request->user('admin'), null, (bool) $validated['is_active']);
        } catch (StaffException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json([
            'data' => (new MerchantStaffResource($target->refresh()->loadMissing('role')))->resolve($request),
        ]);
    }
}
