<?php

use App\Http\Controllers\Merchant\OnboardingGuideController;
use Illuminate\Support\Facades\Route;

// The guided-setup tasklist (owner, 2026-08-25) — the panel's sidebar foot
// and the app's home screen.
//
// No permission middleware and no {id}: this is the SIGNED-IN PERSON's own
// onboarding state, so the account acted on is always the authenticated one
// and one staffer can never reach another's. See OnboardingGuideController.
//
// No EnsureMerchantApproved either. A draft store is exactly the store that
// needs this list — its first task is finishing setup.
//
// The GET is deliberately cheap enough to hang off every page load: one
// query while the guide is live, none at all once it is skipped or past
// its five days (OnboardingGuide). The writes are throttled because they
// are writes, not because anyone would want to send them twice.
Route::prefix('merchant')->middleware('auth:merchant')->group(function (): void {
    Route::get('onboarding', [OnboardingGuideController::class, 'show']);

    Route::post('onboarding/skip', [OnboardingGuideController::class, 'skip'])
        ->middleware('throttle:20,1');

    Route::post('onboarding/tour', [OnboardingGuideController::class, 'completeTour'])
        ->middleware('throttle:20,1');
});
