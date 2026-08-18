<?php

use App\Http\Controllers\Devices\MerchantDevicesController;
use App\Http\Controllers\Merchant\BankAccountController;
use App\Http\Controllers\Merchant\BranchesController;
use App\Http\Controllers\Merchant\CustomerLookupController;
use App\Http\Controllers\Merchant\PreferencesController;
use App\Http\Controllers\Merchant\ProfileController;
use App\Http\Controllers\Merchant\PublicationController;
use App\Http\Controllers\Merchant\ReverseGeocodeController;
use App\Http\Controllers\Merchant\RolesController;
use App\Http\Controllers\Merchant\StaffController;
use App\Http\Middleware\EnsureMerchantApproved;
use Illuminate\Support\Facades\Route;

// Merchant settings module, one permission per surface (PLAN §13b staff
// permissions), grouped as the panel groups them:
//
//  - Store: the profile READ and WRITE, and the branch estate — including
//    the map pin. Seeded to Manager and above, except the profile write,
//    which stays with the Owner: it is the store's public identity.
//  - Account: the bank account, staff accounts, the roles those accounts
//    stand on, and preferences — the money destination and who may sign in.
//    Seeded to the Owner alone.
//  - Till: the customer lookup, which feeds the credit screen and so seeds
//    to every role. Throttled 30/min per user like the manual-credit POST
//    itself (§11), with a per-MERCHANT daily miss cap + audit logging
//    inside the controller so staff accounts cannot multiply the
//    enumeration budget.
//
// Orthogonal to the permission, the writes that shape what the review queue
// sees — or that mint accounts which could not be used yet — additionally
// need an APPROVED store (EnsureMerchantApproved, 409 store_not_approved):
// the profile PATCH, the branch writes and the staff invite. Reads stay
// open throughout so the panel can render while the store waits. The
// permission gate is always listed FIRST: a user who may not act must be
// refused before the response reveals the store's standing.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('customers/lookup', CustomerLookupController::class)
        ->middleware(['merchant.can:customers.lookup', 'throttle:30,1']);

    Route::get('profile', [ProfileController::class, 'show'])
        ->middleware('merchant.can:profile.view');

    // Post-approval stores only: before approval the wizard is the sole
    // write path, and a pending_review store must not rewrite the fields
    // the superadmin queue is reviewing (EnsureMerchantApproved).
    Route::patch('profile', [ProfileController::class, 'update'])
        ->middleware(['merchant.can:profile.edit', EnsureMerchantApproved::class]);

    // The store's own on/off switch. TRADING tier: only a store that is
    // actually active has anything to pause, and a suspended one must not be
    // able to "republish" itself into a listing it is not entitled to.
    Route::post('publication', PublicationController::class)
        ->middleware([
            'merchant.can:store.publication',
            EnsureMerchantApproved::class.':'.EnsureMerchantApproved::TRADING,
            'throttle:20,1',
        ]);

    // Pin → written address, so a required field can be filled by tapping a
    // map instead of typing. Open to anyone who may touch the estate, in the
    // wizard as well as in settings — a draft store has branches to describe
    // too. Throttled because it stands in front of somebody else's service.
    Route::get('branches/reverse-geocode', ReverseGeocodeController::class)
        ->middleware(['merchant.can:branches.view', 'throttle:60,1']);

    Route::get('branches', [BranchesController::class, 'index'])
        ->middleware('merchant.can:branches.view');

    // Branch WRITES join every other write behind approval
    // (EnsureMerchantApproved): before approval the setup wizard is the
    // sole write path, and a pending_review store must not grow, rename or
    // drop the estate the superadmin queue is looking at. Reads stay open —
    // the panel renders them while the store waits.
    Route::post('branches', [BranchesController::class, 'store'])
        ->middleware(['merchant.can:branches.create', EnsureMerchantApproved::class]);

    Route::patch('branches/{id}', [BranchesController::class, 'update'])
        ->whereNumber('id')
        ->middleware(['merchant.can:branches.edit', EnsureMerchantApproved::class]);

    Route::delete('branches/{id}', [BranchesController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware(['merchant.can:branches.delete', EnsureMerchantApproved::class]);

    // Reading the payout destination is its own permission (D6). The store
    // owner asked to see the account without being able to repoint it, and
    // until now the only place the value surfaced was inside settlement
    // payment instructions.
    Route::get('bank-account', [BankAccountController::class, 'show'])
        ->middleware('merchant.can:bank_account.view');

    Route::patch('bank-account', [BankAccountController::class, 'update'])
        ->middleware('merchant.can:bank_account.update');

    Route::get('staff', [StaffController::class, 'index'])
        ->middleware('merchant.can:staff.view');

    // Inviting is approved-only: the whole panel is off-limits while the
    // store is draft / pending_review / rejected (the wizard is the only
    // screen, and it needs setup.view), so an account minted before
    // approval could not do one useful thing.
    Route::post('staff', [StaffController::class, 'store'])
        ->middleware(['merchant.can:staff.invite', EnsureMerchantApproved::class]);

    Route::patch('staff/{id}', [StaffController::class, 'update'])
        ->whereNumber('id')
        ->middleware('merchant.can:staff.edit');

    // The MR8 password reset rides `staff.edit`, NOT `staff.invite`: it is
    // an operation on an EXISTING account, like the PATCH above — and like
    // that PATCH it carries no approval gate, because resetting the password
    // of a cashier whose phone was taken is exactly what a suspended or
    // still-waiting store must stay able to do. The invite's slug and its
    // approval gate belong to minting NEW accounts. The temp password is in
    // THIS response and never again.
    Route::post('staff/{id}/reset-password', [StaffController::class, 'resetPassword'])
        ->whereNumber('id')
        ->middleware('merchant.can:staff.edit');

    // The permission CATALOGUE, published so the roles screen renders the
    // groups and their wording from the server rather than hardcoding a
    // copy that goes stale on the next deploy (D8). Read-only and gated
    // with the roles list itself — it is that screen's other half.
    Route::get('permissions', [RolesController::class, 'permissions'])
        ->middleware('merchant.can:roles.view');

    Route::get('roles', [RolesController::class, 'index'])
        ->middleware('merchant.can:roles.view');

    // Role WRITES carry no approval gate, unlike the staff invite beside
    // them: a role mints no account and shows the review queue nothing, so
    // a store still waiting on approval can shape the roles it will hand
    // out the day it starts trading.
    Route::post('roles', [RolesController::class, 'store'])
        ->middleware('merchant.can:roles.manage');

    Route::patch('roles/{id}', [RolesController::class, 'update'])
        ->whereNumber('id')
        ->middleware('merchant.can:roles.manage');

    Route::delete('roles/{id}', [RolesController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('merchant.can:roles.manage');

    Route::patch('preferences', [PreferencesController::class, 'update'])
        ->middleware('merchant.can:preferences.update');

    // A staff member's OWN signed-in app devices. Deliberately ungated by
    // the permission catalogue: signing your own phone out is a personal
    // security act, not a merchant capability, and the account that most
    // needs it — a cashier whose phone was taken — is the one holding the
    // fewest permissions. Scoped to self inside the controller, so no
    // permission would make it reach another person's devices anyway.
    // Also mounted on the app itself in routes/api/mobile.php.
    Route::get('devices', [MerchantDevicesController::class, 'index']);
    Route::delete('devices/{id}', [MerchantDevicesController::class, 'destroy'])->whereNumber('id');
    Route::delete('devices', [MerchantDevicesController::class, 'destroyAll']);
});
