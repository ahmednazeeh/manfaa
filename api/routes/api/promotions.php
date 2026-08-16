<?php

use App\Http\Controllers\Admin\PromotionsController as AdminPromotionsController;
use App\Http\Controllers\Merchant\PromotionController;
use App\Http\Middleware\EnsureMerchantApproved;
use Illuminate\Support\Facades\Route;

// Promotions engine (PLAN §12 Phase 3). The merchant surface exposes only
// the domain's lifecycle: draft, publish, cancel-draft, list — no update
// and no early-end route exists, because a published promotion is immutable
// for its stated duration (PLAN §7). Creating and publishing additionally
// require a TRADING store (EnsureMerchantApproved:trading — active only):
// a store still in the onboarding review queue must not stage promotions
// that spring live the moment it is activated, and neither must a SUSPENDED
// one, whose campaign would go live on reinstatement even though suspension
// exists precisely because it is not paying for the cashback it creates.
// Cancel stays open so a store sent back to rejected — or suspended
// mid-draft — is never stranded with an uncancellable draft.
//
// Publish is its own permission, deliberately: a draft costs nothing and a
// published promotion is immutable for its stated duration, so the two are
// different trust levels even though the same person usually does both.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('promotions', [PromotionController::class, 'index'])
        ->middleware('merchant.can:promotions.view');

    Route::post('promotions', [PromotionController::class, 'store'])
        ->middleware(['merchant.can:promotions.create', EnsureMerchantApproved::class.':trading']);

    Route::post('promotions/{id}/publish', [PromotionController::class, 'publish'])
        ->whereNumber('id')
        ->middleware(['merchant.can:promotions.publish', EnsureMerchantApproved::class.':trading']);

    Route::post('promotions/{id}/cancel', [PromotionController::class, 'cancel'])
        ->whereNumber('id')
        ->middleware('merchant.can:promotions.cancel');
});

Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('promotions', [AdminPromotionsController::class, 'index']);
});
