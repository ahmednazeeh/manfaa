<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Onboarding\OnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantProfileResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            // Curated categories only (§1 decision 2026-08-15): the slug
            // must be an ACTIVE store_categories row.
            'category' => [
                'sometimes', 'nullable', 'string', 'max:80',
                Rule::exists('store_categories', 'slug')->where('active', true),
            ],
            'channel' => ['sometimes', 'string', Rule::in(OnboardingService::CHANNELS)],
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
