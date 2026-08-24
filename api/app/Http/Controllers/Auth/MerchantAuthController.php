<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantUserResource;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
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

        Cookie::queue(self::authMarker());

        return new MerchantUserResource($user->loadMissing('merchant'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('merchant')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Cookie::queue(Cookie::forget('manfaa-auth'));

        return response()->noContent();
    }

    public function me(Request $request): MerchantUserResource
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        // Refresh the marker on every authenticated read, so sessions that
        // predate it (or outlive one marker) heal on their next visit.
        Cookie::queue(self::authMarker());

        return new MerchantUserResource($user->loadMissing('merchant'));
    }

    /**
     * The panel's root fork (apps/merchant app/page.tsx) needs "is someone
     * actually signed in?" without an API round-trip. The session cookie
     * cannot answer that: /sanctum/csrf-cookie mints manfaa-sid for ANYONE
     * who ever opened the login form, and an anonymous visitor then bounced
     * / -> /dashboard -> /login for the whole 8h cookie life (owner report,
     * 2026-08-24). This marker exists ONLY on real logins and dies on
     * logout, so cookie PRESENCE finally means what the fork thinks it
     * means. Same lifetime as the session; carries no data worth reading.
     */
    private static function authMarker(): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make(
            'manfaa-auth',
            '1',
            (int) config('session.lifetime'),
            '/',
            null,          // host-only, like the session itself
            true,          // secure
            true,          // httpOnly
            false,
            'lax',
        );
    }
}
