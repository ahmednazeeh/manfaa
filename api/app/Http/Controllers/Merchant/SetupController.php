<?php

declare(strict_types=1);

namespace App\Http\Controllers\Merchant;

use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Platform\RateNotPricedException;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantUser;
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

        $url = $this->onboarding->storeLogo($merchant, $request->file('logo'));

        return response()->json(['data' => ['logo_url' => $url]]);
    }

    public function updateRate(Request $request): JsonResponse
    {
        // §4: integer basis points, 50 to the structural cap; the live fee
        // tier schedule's own ceiling is enforced in the service
        // (rate_not_priced below).
        $validated = $request->validate([
            'rate_bp' => ['required', 'integer', 'min:50', 'max:2000'],
        ]);

        $merchant = $this->merchant($request);

        /** @var MerchantUser $user */
        $user = $request->user('merchant');

        try {
            $this->onboarding->setRate($merchant, $user, (int) $validated['rate_bp']);
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
