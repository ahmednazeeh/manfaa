<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantUserResource;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MerchantAuthController extends Controller
{
    public function login(Request $request): MerchantUserResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('merchant')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // A deactivated merchant user fails exactly like a wrong password —
        // no account-state oracle. The Authenticated-event listener
        // (MerchantSettingsServiceProvider) may already have logged the
        // session out, leaving a null user here; both read as a plain
        // failed login. Strict === false so a not-yet-migrated is_active
        // column (null) never locks anyone out.
        $user = Auth::guard('merchant')->user();

        if (! $user instanceof MerchantUser || $user->is_active === false) {
            Auth::guard('merchant')->logout();

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return new MerchantUserResource($user->loadMissing('merchant'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('merchant')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): MerchantUserResource
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return new MerchantUserResource($user->loadMissing('merchant'));
    }
}
