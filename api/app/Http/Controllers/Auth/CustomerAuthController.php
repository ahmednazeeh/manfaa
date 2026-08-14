<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
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

        $request->session()->regenerate();

        return new CustomerResource(Auth::guard('customer')->user());
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
