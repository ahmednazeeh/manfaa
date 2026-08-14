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

        $request->session()->regenerate();

        /** @var MerchantUser $user */
        $user = Auth::guard('merchant')->user();

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
