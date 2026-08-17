<?php

use App\Http\Controllers\Admin\CustomersController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

// The superadmin's customer account controls (owner request 2026-08-17) —
// the customer-side sibling of the merchant controls in standing.php:
// find an account, read its standing, and rescue it (phone change, web
// password reset, enable/disable).
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    // Reads are ordinary admin work, like the claims queue and holds.
    Route::get('customers', [CustomersController::class, 'index']);
    Route::get('customers/{customer}', [CustomersController::class, 'show'])->whereNumber('customer');

    // Rewriting a customer's login identity, minting them a password and
    // shutting the account are superadmin-only, exactly like the merchant
    // staff rescue actions beside them.
    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::patch('customers/{customer}', [CustomersController::class, 'update'])->whereNumber('customer');
        Route::post('customers/{customer}/reset-password', [CustomersController::class, 'resetPassword'])->whereNumber('customer');
        Route::post('customers/{customer}/status', [CustomersController::class, 'status'])->whereNumber('customer');
    });
});
