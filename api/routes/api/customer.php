<?php

use App\Http\Controllers\Admin\ClaimsController as AdminClaimsController;
use App\Http\Controllers\Customer\BalanceController;
use App\Http\Controllers\Customer\ClaimsController;
use App\Http\Controllers\Customer\DiscoveryController;
use App\Http\Controllers\Customer\OtpAuthController;
use App\Http\Controllers\Customer\PayoutAccountController;
use App\Http\Controllers\Customer\TransactionsController;
use Illuminate\Support\Facades\Route;

// Customer platform (§10 apps/web, §12 Phase 3): OTP signup, balance,
// history, payout account, claims — plus the admin claims queue and the
// public discovery feed. The existing password login in routes/api.php is
// untouched.

Route::prefix('customer/auth')->group(function () {
    // request-otp carries its own dual limiter (3/h per phone + 10/h per IP)
    // inside the controller; this route throttle is a coarse backstop.
    Route::post('request-otp', [OtpAuthController::class, 'requestOtp'])->middleware('throttle:30,1');
    Route::post('verify-otp', [OtpAuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('register', [OtpAuthController::class, 'register'])->middleware('throttle:10,1');
});

Route::prefix('customer')->middleware('auth:customer')->group(function () {
    Route::get('balance', [BalanceController::class, 'show']);
    Route::get('transactions', [TransactionsController::class, 'index']);

    Route::get('payout-account', [PayoutAccountController::class, 'show']);
    Route::post('payout-account', [PayoutAccountController::class, 'store']);

    Route::get('claims', [ClaimsController::class, 'index']);
    Route::post('claims', [ClaimsController::class, 'store'])->middleware('throttle:10,1');
});

Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('claims', [AdminClaimsController::class, 'index']);
    Route::post('claims/{id}/approve', [AdminClaimsController::class, 'approve'])->whereNumber('id');
    Route::post('claims/{id}/reject', [AdminClaimsController::class, 'reject'])->whereNumber('id');
});

// PUBLIC — no auth, throttled per IP, dataset cached 60s in the service.
Route::get('discover', [DiscoveryController::class, 'index'])->middleware('throttle:60,1');
