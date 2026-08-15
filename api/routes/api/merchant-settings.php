<?php

use App\Http\Controllers\Merchant\BankAccountController;
use App\Http\Controllers\Merchant\BranchesController;
use App\Http\Controllers\Merchant\CustomerLookupController;
use App\Http\Controllers\Merchant\PreferencesController;
use App\Http\Controllers\Merchant\ProfileController;
use App\Http\Controllers\Merchant\StaffController;
use App\Http\Middleware\EnsureMerchantApproved;
use Illuminate\Support\Facades\Route;

// Merchant settings module, split across the three tiers (PLAN §1):
//
//  - OWNER only (merchant.role:owner, 403 code owner_required): the bank
//    account, staff management, preferences and the profile WRITE — the
//    money destination, who may log in, and the store's public identity.
//  - MANAGER or above (merchant.role:manager, 403 code manager_required):
//    branches CRUD and the profile READ. A manager runs the shop's
//    operations; they never repoint the payout account or mint accounts.
//  - Every merchant user: the customer lookup — it feeds the credit screen,
//    and posting credits is staff work — throttled 30/min per user like the
//    manual-credit POST itself (§11), with a per-MERCHANT daily miss cap +
//    audit logging inside the controller so staff accounts cannot multiply
//    the enumeration budget.
//
// Orthogonal to the tier, the writes that shape what the review queue sees
// — or that mint accounts which could not be used yet — additionally need
// an APPROVED store (EnsureMerchantApproved, 409 store_not_approved): the
// profile PATCH, the branch writes and the staff invite. Reads stay open
// throughout so the panel can render while the store waits.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('customers/lookup', CustomerLookupController::class)->middleware('throttle:30,1');

    Route::middleware('merchant.role:manager')->group(function () {
        Route::get('profile', [ProfileController::class, 'show']);

        Route::get('branches', [BranchesController::class, 'index']);

        // Branch WRITES join every other manager write behind approval
        // (EnsureMerchantApproved): before approval the setup wizard is the
        // sole write path, and a pending_review store must not grow, rename
        // or drop the estate the superadmin queue is looking at. Reads stay
        // open — the panel renders them while the store waits.
        Route::middleware(EnsureMerchantApproved::class)->group(function () {
            Route::post('branches', [BranchesController::class, 'store']);
            Route::patch('branches/{id}', [BranchesController::class, 'update'])->whereNumber('id');
            Route::delete('branches/{id}', [BranchesController::class, 'destroy'])->whereNumber('id');
        });
    });

    Route::middleware('merchant.role:owner')->group(function () {
        // Post-approval stores only: before approval the wizard is the sole
        // write path, and a pending_review store must not rewrite the fields
        // the superadmin queue is reviewing (EnsureMerchantApproved).
        Route::patch('profile', [ProfileController::class, 'update'])->middleware(EnsureMerchantApproved::class);

        Route::patch('bank-account', [BankAccountController::class, 'update']);

        Route::get('staff', [StaffController::class, 'index']);

        // Inviting is approved-only: the whole panel is off-limits while
        // the store is draft / pending_review / rejected (the wizard is the
        // only screen, and it is owner-only), so a manager or staff account
        // minted before approval could not do one useful thing — it would
        // land on an "owner access only" wall with nothing but logout.
        Route::post('staff', [StaffController::class, 'store'])->middleware(EnsureMerchantApproved::class);

        Route::patch('staff/{id}', [StaffController::class, 'update'])->whereNumber('id');

        Route::patch('preferences', [PreferencesController::class, 'update']);
    });
});
