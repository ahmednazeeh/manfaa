<?php

use App\Http\Controllers\Admin\PromotionsController as AdminPromotionsController;
use App\Http\Controllers\Merchant\PromotionController;
use Illuminate\Support\Facades\Route;

// Promotions engine (PLAN §12 Phase 3). The merchant surface exposes only
// the domain's lifecycle: draft, publish, cancel-draft, list — no update
// and no early-end route exists, because a published promotion is immutable
// for its stated duration (PLAN §7).
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::post('promotions', [PromotionController::class, 'store']);
    Route::post('promotions/{id}/publish', [PromotionController::class, 'publish'])->whereNumber('id');
    Route::post('promotions/{id}/cancel', [PromotionController::class, 'cancel'])->whereNumber('id');
});

Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('promotions', [AdminPromotionsController::class, 'index']);
});
