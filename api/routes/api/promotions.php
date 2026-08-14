<?php

use App\Http\Controllers\Admin\PromotionsController as AdminPromotionsController;
use App\Http\Controllers\Merchant\PromotionController;
use App\Http\Middleware\EnsureMerchantApproved;
use App\Http\Middleware\EnsureMerchantOwner;
use Illuminate\Support\Facades\Route;

// Promotions engine (PLAN §12 Phase 3). The merchant surface exposes only
// the domain's lifecycle: draft, publish, cancel-draft, list — no update
// and no early-end route exists, because a published promotion is immutable
// for its stated duration (PLAN §7). Creating and publishing additionally
// require an APPROVED store (EnsureMerchantApproved): a store still in the
// onboarding review queue must not stage promotions that spring live the
// moment it is activated. Cancel stays open so a store sent back to
// rejected is never stranded with an uncancellable draft.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::post('promotions', [PromotionController::class, 'store'])->middleware([EnsureMerchantOwner::class, EnsureMerchantApproved::class]);
    Route::post('promotions/{id}/publish', [PromotionController::class, 'publish'])->whereNumber('id')->middleware([EnsureMerchantOwner::class, EnsureMerchantApproved::class]);
    Route::post('promotions/{id}/cancel', [PromotionController::class, 'cancel'])->whereNumber('id')->middleware(EnsureMerchantOwner::class);
});

Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('promotions', [AdminPromotionsController::class, 'index']);
});
