<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function login(Request $request): AdminUserResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('admin')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // A deactivated admin fails exactly like a wrong password — no
        // account-state oracle. The Authenticated-event listener
        // (PlatformServiceProvider) may already have logged the session out,
        // leaving a null user here; both read as a plain failed login.
        // Strict === false so a not-yet-migrated is_active column (null)
        // never locks anyone out.
        $user = Auth::guard('admin')->user();

        if (! $user instanceof AdminUser || $user->is_active === false) {
            Auth::guard('admin')->logout();

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return new AdminUserResource($user);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): AdminUserResource
    {
        return new AdminUserResource($request->user('admin'));
    }
}
