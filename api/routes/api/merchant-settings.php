<?php

use App\Http\Controllers\Merchant\BankAccountController;
use App\Http\Controllers\Merchant\BranchesController;
use App\Http\Controllers\Merchant\CustomerLookupController;
use App\Http\Controllers\Merchant\PreferencesController;
use App\Http\Controllers\Merchant\ProfileController;
use App\Http\Controllers\Merchant\StaffController;
use App\Http\Middleware\EnsureMerchantOwner;
use Illuminate\Support\Facades\Route;

// Merchant settings module: profile, bank account, branches, staff and
// preferences are OWNER-only (EnsureMerchantOwner, 403 code
// owner_required). The customer lookup stays staff-accessible — it feeds
// the credit screen, and posting credits is staff work — throttled 30/min
// per user like the manual-credit POST itself (§11), with a per-MERCHANT
// daily miss cap + audit logging inside the controller so staff accounts
// cannot multiply the enumeration budget.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('customers/lookup', CustomerLookupController::class)->middleware('throttle:30,1');

    Route::middleware(EnsureMerchantOwner::class)->group(function () {
        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);

        Route::patch('bank-account', [BankAccountController::class, 'update']);

        Route::get('branches', [BranchesController::class, 'index']);
        Route::post('branches', [BranchesController::class, 'store']);
        Route::patch('branches/{id}', [BranchesController::class, 'update'])->whereNumber('id');
        Route::delete('branches/{id}', [BranchesController::class, 'destroy'])->whereNumber('id');

        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store']);
        Route::patch('staff/{id}', [StaffController::class, 'update'])->whereNumber('id');

        Route::patch('preferences', [PreferencesController::class, 'update']);
    });
});
