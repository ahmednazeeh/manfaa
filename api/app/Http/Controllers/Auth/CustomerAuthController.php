<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CustomerAuthController extends Controller
{
    public function login(Request $request): CustomerResource
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('customer')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'phone' => __('auth.failed'),
            ]);
        }

        // A suspended (or closed) account fails exactly like a wrong
        // password — the same no-oracle stance as the admin login. A
        // password proves nothing about who holds the phone, so unlike the
        // OTP paths there is no possession that would justify naming the
        // account's standing. The Authenticated listener
        // (CustomersServiceProvider) may already have logged the fresh
        // session out, leaving a null user here; both read as failure.
        $user = Auth::guard('customer')->user();

        if (! $user instanceof Customer || ! $user->mayUseMobileApp()) {
            Auth::guard('customer')->logout();

            throw ValidationException::withMessages([
                'phone' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return new CustomerResource($user);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): CustomerResource
    {
        return new CustomerResource($request->user('customer'));
    }
}
