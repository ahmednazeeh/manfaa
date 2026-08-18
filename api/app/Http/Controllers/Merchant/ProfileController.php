<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Rules\MaxWords;
use App\Domain\Approvals\ChangeRequestService;
use App\Domain\Discovery\DiscoveryService;
use App\Domain\Onboarding\OnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantChangeRequestResource;
use App\Http\Resources\MerchantProfileResource;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\StoreCategory;
use Closure;
use Illuminate\Http\JsonResponse;
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
 *
 * MR9 splits the PATCH in two: a live store's public claims queue for admin
 * review (202), its contact details apply on the spot (200). The split lives
 * in ChangeRequestService, and it is applied HERE — once — because the web
 * panel and the merchant app mount this same controller.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly ChangeRequestService $changes) {}

    public function show(Request $request): MerchantProfileResource
    {
        return new MerchantProfileResource($this->merchant($request));
    }

    public function update(Request $request): MerchantProfileResource|JsonResponse
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
            'description' => ['sometimes', 'nullable', 'string', new MaxWords(180)],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            // A bare domain is what people type; normalising rather than
            // refusing it is the difference between a working link and a
            // store that gives up on the field.
            'website_url' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // MR9. A LIVE store's public identity — name, Dhivehi name, category,
        // channel, the "what earns cashback" promise, website — is a claim a
        // shopper reads and trusts, so it queues for admin review instead of
        // going live silently. The contact numbers do NOT: a wrong number
        // means customers cannot reach the store, and holding that fix for a
        // reviewer would only prolong the harm.
        //
        // Both halves of a mixed write are honoured in the one request: the
        // instant keys apply now, the gated ones become a change request, and
        // the response says which happened (202 + change_request when
        // anything queued). A store that is NOT live writes straight through
        // — see ChangeRequestService::gates().
        $split = ChangeRequestService::splitProfileInput($validated);
        $gates = ChangeRequestService::gates($merchant);

        $merchant->fill($gates ? $split['instant'] : $validated)->save();

        // Category, channel and the terms text all render on the public
        // card and store page, which are read models cached for 60s
        // (DiscoveryService) — and so do the contact numbers that apply
        // instantly here. The logo path has always dropped them; a profile
        // save did not, so the storefront kept serving the previous category
        // or channel for up to a minute after the owner changed it.
        // Unconditional: a save is a rare owner action, and reasoning about
        // which field is public is exactly the kind of subtlety that rots the
        // next time a field is added here.
        DiscoveryService::forgetMerchant($merchant);

        /** @var MerchantUser $actor */
        $actor = $request->user('merchant');

        $queued = $gates && $split['gated'] !== []
            ? $this->changes->submitProfileChange($merchant, $actor, $split['gated'])
            : null;

        // Built AFTER the submission, so `pending_change` on the profile it
        // carries is the state the request just created.
        $profile = new MerchantProfileResource($merchant->refresh());

        // A null queue means nothing genuinely changed — the panels PATCH the
        // whole form, so a phone-number save arrives carrying the store's own
        // name back at us. That is a 200, not a review queue.
        return $queued === null
            ? $profile
            : MerchantChangeRequestResource::accepted($queued, ['profile' => $profile->resolve($request)]);
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
