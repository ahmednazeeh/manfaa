<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Discovery\DiscoveryService;
use App\Domain\Onboarding\OnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantProfileResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\StoreCategory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The merchant profile: `profile.view` on the GET, `profile.edit` on the
 * PATCH — reading the store's own record is operational, rewriting its
 * public identity is not.
 * Both names are editable — the store knows what it is called better than
 * we do — but the SLUG never moves with them. It is the address on every
 * shared link, QR code and printed card already in circulation, so a
 * rebrand changes the words on the page and leaves the door where it was.
 * That divergence is the point, not a defect: `/store/tea-plus` reading
 * "Chai House" is a store that renamed, while a slug that followed the
 * name would be a store nobody can find again.
 */
class ProfileController extends Controller
{
    public function show(Request $request): MerchantProfileResource
    {
        return new MerchantProfileResource($this->merchant($request));
    }

    public function update(Request $request): MerchantProfileResource
    {
        $merchant = $this->merchant($request);

        $validated = $request->validate([
            // Curated categories only (§1 decision 2026-08-15) — see
            // categoryRule() for the one deliberate exception.
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'name_dv' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80', $this->categoryRule($merchant)],
            'channel' => ['sometimes', 'string', Rule::in(OnboardingService::CHANNELS)],
            'eligibility_basis' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            // A bare domain is what people type; normalising rather than
            // refusing it is the difference between a working link and a
            // store that gives up on the field.
            'website_url' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $merchant->fill($validated)->save();

        // Category, channel and the terms text all render on the public
        // card and store page, which are read models cached for 60s
        // (DiscoveryService). The logo path has always dropped them; a
        // profile save did not, so the storefront kept serving the previous
        // category or channel for up to a minute after the owner changed
        // it. Unconditional: a save is a rare owner action, and reasoning
        // about which field is public is exactly the kind of subtlety that
        // rots the next time a field is added here.
        DiscoveryService::forgetMerchant($merchant);

        return new MerchantProfileResource($merchant->refresh());
    }

    /**
     * A category must be an ACTIVE curated slug — with one exception: the
     * value the store ALREADY holds is always accepted.
     *
     * When a superadmin retires a category, every store still on it would
     * otherwise be unable to save ANY profile field — the panel PATCHes the
     * whole form, so editing a contact phone number returned 422 on
     * `category` until the owner re-picked one. Accepting the unchanged
     * value costs nothing (it is already the persisted state; this write
     * does not spread a retired category anywhere new) and it keeps the
     * retirement advisory rather than a save trap. Choosing a DIFFERENT
     * category still requires an active one, `category_retired` on the
     * profile payload tells the panel to prompt for a new pick, and the
     * onboarding submit/approve gates still demand an active category
     * before a store can go (or go back) live.
     */
    private function categoryRule(Merchant $merchant): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($merchant): void {
            if ($value === $merchant->category) {
                return;
            }

            $exists = StoreCategory::query()
                ->where('slug', $value)
                ->where('active', true)
                ->exists();

            if (! $exists) {
                $fail('The selected category is not available.');
            }
        };
    }

    private function merchant(Request $request): Merchant
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user->merchant;
    }
}
