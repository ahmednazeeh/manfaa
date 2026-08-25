<?php

use App\Http\Controllers\Merchant\AccountClosureController;
use App\Http\Controllers\Merchant\SetupController;
use App\Http\Controllers\Merchant\SignupController;
use Illuminate\Support\Facades\Route;

// Store self-signup + setup wizard (§1 decision 2026-08-15).
//
// The signup steps are PUBLIC: phone OTP → short-lived signup token →
// register creates a DRAFT merchant plus its owner and logs the owner in.
// request-otp carries its own dual limiter (3/h per phone + 10/h per IP,
// merchant-scoped keys) inside the controller; these route throttles are a
// coarse backstop, mirroring the customer OTP routes.
Route::prefix('merchant/signup')->group(function () {
    // What the form must know before it is submitted: the validation-window
    // range the platform currently allows, so the field renders its own
    // limit instead of a hardcoded guess that goes stale the day an admin
    // moves the ceiling. Public like the rest of signup, and it discloses
    // nothing about any store.
    Route::get('options', [SignupController::class, 'options'])->middleware('throttle:60,1');

    Route::post('request-otp', [SignupController::class, 'requestOtp'])->middleware('throttle:30,1');
    Route::post('verify-otp', [SignupController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('register', [SignupController::class, 'register'])->middleware('throttle:10,1');
});

// The wizard sits in the `setup.*` permissions, seeded to the Owner alone —
// it is the store's application for approval, and the only screen a
// pre-approval account ever sees. Reading it is separate from filling it in
// and separate again from SUBMITTING: submit is the irreversible act that
// puts the store in front of the review queue.
//
// Draft, rejected AND pending/active merchants may READ the setup state
// (the panel renders the waiting screen from it); writes are gated in the
// domain layer to draft or rejected stores — except the logo, which stays
// open to ACTIVE merchants for post-approval changes and is also exposed
// under /merchant/settings.
//
// Both logo routes answer to `branding.update` rather than `setup.edit`:
// they are one handler changing one thing, and which URL the panel reached
// it through is not an authority question.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('setup', [SetupController::class, 'show'])
        ->middleware('merchant.can:setup.view');

    Route::patch('setup/profile', [SetupController::class, 'updateProfile'])
        ->middleware('merchant.can:setup.edit');

    Route::patch('setup/location', [SetupController::class, 'updateLocation'])
        ->middleware('merchant.can:setup.edit');

    Route::post('setup/logo', [SetupController::class, 'storeLogo'])
        ->middleware(['merchant.can:branding.update', 'throttle:20,1']);

    Route::patch('setup/rate', [SetupController::class, 'updateRate'])
        ->middleware('merchant.can:setup.edit');

    Route::post('setup/submit', [SetupController::class, 'submit'])
        ->middleware(['merchant.can:setup.submit', 'throttle:20,1']);

    Route::post('settings/logo', [SetupController::class, 'storeLogo'])
        ->middleware(['merchant.can:branding.update', 'throttle:20,1']);
});

// Self-service store closure (store-readiness 2026-08-17): the public
// merchant /account-deletion page. Phone possession (the store's contact
// number) proven by OTP is the credential; a store owing money is refused
// at confirm — settling stays open, closing waits.
Route::prefix('merchant/account-closure')->group(function () {
    Route::post('request-otp', [AccountClosureController::class, 'requestOtp'])->middleware('throttle:30,1');
    Route::post('verify', [AccountClosureController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('confirm', [AccountClosureController::class, 'confirm'])->middleware('throttle:10,1');
});
