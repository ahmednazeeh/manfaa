<?php

use App\Http\Controllers\Admin\ChangeRequestController;
use App\Http\Controllers\MerchantChangeRequestLogoController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

// Store-CHANGE review (MR9, design decided 2026-08-18). The sibling of
// routes/api/store-review.php: that queue reviews stores that have never
// been live, this one reviews what a LIVE store wants to change about the
// claims a shopper reads — its name, category, channel, logo, website, its
// "what earns cashback" promise, and its branch estate.
//
// The same split of authority, deliberately: any admin may READ the queue
// and open a request, but only a SUPERADMIN approves or refuses one, exactly
// as with store activation, platform bank accounts and admin accounts. A
// refusal requires a reason, which reaches the merchant verbatim.
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('change-requests', [ChangeRequestController::class, 'index']);
    Route::get('change-requests/{id}', [ChangeRequestController::class, 'show'])->whereNumber('id');

    Route::middleware(EnsureSuperadmin::class)->group(function () {
        Route::post('change-requests/{id}/approve', [ChangeRequestController::class, 'approve'])->whereNumber('id');
        Route::post('change-requests/{id}/reject', [ChangeRequestController::class, 'reject'])->whereNumber('id');
    });
});

// The staged logo of a queued change — the one file a review sheet cannot
// render from the store's own row, because the whole point is that the row
// has not moved yet. No auth middleware and the handler authorises (the
// pattern routes/api/logos.php explains): the same URL is fetched as an
// <img> by the admin panel and by the merchant's own panel, from two
// different session guards. Never public — see the controller.
Route::get('change-requests/{id}/logo', MerchantChangeRequestLogoController::class)
    ->whereNumber('id')
    ->middleware('throttle:240,1');
