<?php

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
    Route::post('request-otp', [SignupController::class, 'requestOtp'])->middleware('throttle:30,1');
    Route::post('verify-otp', [SignupController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('register', [SignupController::class, 'register'])->middleware('throttle:10,1');
});

// The wizard is OWNER-only behind the merchant guard. Draft, rejected AND
// pending/active merchants may READ the setup state (the panel renders the
// waiting screen from it); writes are gated in the domain layer to draft or
// rejected stores — except the logo, which stays open to ACTIVE merchants
// for post-approval changes and is also exposed under /merchant/settings.
Route::prefix('merchant')->middleware(['auth:merchant', 'merchant.role:owner'])->group(function () {
    Route::get('setup', [SetupController::class, 'show']);
    Route::patch('setup/profile', [SetupController::class, 'updateProfile']);
    Route::post('setup/logo', [SetupController::class, 'storeLogo'])->middleware('throttle:20,1');
    Route::patch('setup/rate', [SetupController::class, 'updateRate']);
    Route::post('setup/submit', [SetupController::class, 'submit'])->middleware('throttle:20,1');

    Route::post('settings/logo', [SetupController::class, 'storeLogo'])->middleware('throttle:20,1');
});
