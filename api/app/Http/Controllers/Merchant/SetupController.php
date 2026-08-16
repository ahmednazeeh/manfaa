<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Money\Percent;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Platform\RateNotPricedException;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Rules\PercentRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner-only store setup wizard (§1 decision 2026-08-15), resumable:
 * GET returns everything needed to continue (completed steps, values, the
 * curated category list, rate bounds, rejection reason); the step writes
 * are allowed only while the store is draft or rejected. The logo action is
 * additionally exposed under /merchant/settings for ACTIVE merchants —
 * post-approval logo changes reuse the same validation and storage.
 */
class SetupController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->onboarding->state($this->merchant($request))]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Curated categories only (§1): the slug must be an ACTIVE row.
            'category' => [
                'sometimes', 'nullable', 'string', 'max:80',
                Rule::exists('store_categories', 'slug')->where('active', true),
            ],
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

        $merchant = $this->merchant($request);

        try {
            $this->onboarding->updateProfile($merchant, $validated);
        } catch (OnboardingException $e) {
            return $this->onboardingError($e);
        }

        return response()->json(['data' => $this->onboarding->state($merchant->refresh())]);
    }

    /**
     * The location step — pins the store's primary branch.
     *
     * Both coordinates are REQUIRED here, unlike the nullable pair
     * BranchesController takes: this endpoint exists to set a pin, and an
     * online-only store that skips the step simply never calls it.
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $merchant = $this->merchant($request);

        try {
            $this->onboarding->setPrimaryLocation($merchant, $validated);
        } catch (OnboardingException $e) {
            return $this->onboardingError($e);
        }

        return response()->json(['data' => $this->onboarding->state($merchant->refresh())]);
    }

    /**
     * Logo upload — wizard AND post-approval settings. Strictly raster
     * images (jpg/png/webp): SVG is scriptable content served from our
     * origin and is refused outright. 2 MB cap, dimension sanity bounds.
     */
    public function storeLogo(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        if (! in_array($merchant->status, OnboardingService::LOGO_STATUSES, true)) {
            return $this->onboardingError(OnboardingException::notEditable($merchant->status));
        }

        $request->validate([
            'logo' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048', // KB
                Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(4096)->maxHeight(4096),
            ],
        ]);

        try {
            $url = $this->onboarding->storeLogo($merchant, $request->file('logo'));
        } catch (OnboardingException $e) {
            return $this->onboardingError($e);
        }

        return response()->json(['data' => ['logo_url' => $url]]);
    }

    public function updateRate(Request $request): JsonResponse
    {
        // PLAN §1 wire format: a 2-decimal percent ("2", "2.5", 2.5),
        // converted to integer basis points by exact integer math. §4
        // bounds are 0.50% to the structural cap; the live fee tier
        // schedule's own ceiling is enforced in the service
        // (rate_not_priced below).
        $validated = $request->validate([
            'cashback_rate_percent' => ['required', PercentRate::cashback()],
        ]);

        $merchant = $this->merchant($request);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        try {
            $this->onboarding->setRate($merchant, $user, Percent::toBasisPoints($validated['cashback_rate_percent']));
        } catch (OnboardingException $e) {
            return $this->onboardingError($e);
        } catch (RateNotPricedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => RateNotPricedException::CODE,
            ], 422);
        }

        return response()->json(['data' => $this->onboarding->state($merchant->refresh())]);
    }

    public function submit(Request $request): JsonResponse
    {
        $merchant = $this->merchant($request);

        try {
            $this->onboarding->submit($merchant);
        } catch (OnboardingException $e) {
            return $this->onboardingError($e);
        }

        return response()->json(['data' => $this->onboarding->state($merchant->refresh())]);
    }

    private function onboardingError(OnboardingException $e): JsonResponse
    {
        $payload = [
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ];

        if ($e->missing !== []) {
            $payload['missing'] = $e->missing;
        }

        return response()->json($payload, $e->httpStatus);
    }

    private function merchant(Request $request): Merchant
    {
        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        return $user->merchant;
    }
}
