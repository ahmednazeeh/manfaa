<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantProfileResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\Request;

/**
 * Owner-editable merchant profile (EnsureMerchantOwner gates the routes).
 * Deliberately excluded: `name` — renaming the business is an identity
 * change and stays admin-only; unknown keys are simply not validated in,
 * so a POSTed `name` is dropped on the floor.
 */
class ProfileController extends Controller
{
    public function show(Request $request): MerchantProfileResource
    {
        return new MerchantProfileResource($this->merchant($request));
    }

    public function update(Request $request): MerchantProfileResource
    {
        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_online' => ['sometimes', 'boolean'],
            'eligibility_basis' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $merchant = $this->merchant($request);
        $merchant->fill($validated)->save();

        return new MerchantProfileResource($merchant->refresh());
    }

    private function merchant(Request $request): Merchant
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user->merchant;
    }
}
