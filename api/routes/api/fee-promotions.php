<?php

use App\Http\Controllers\Admin\FeePromotionsController;
use App\Http\Controllers\Merchant\FeePromotionBannerController;
use App\Http\Controllers\PublicFeePromotionController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

/*
 * PLATFORM FEE PROMOTIONS (owner, 2026-08-25) — three doors, three
 * audiences.
 *
 * 1. THE SETTINGS. Readable by any admin, WRITTEN by a superadmin only — the
 *    same gating the GST switch and the platform's own bank accounts carry,
 *    and for the same reason: one save changes what every merchant on the
 *    platform is charged on every sale from the moment it is thrown.
 */
Route::prefix('admin')->middleware('auth:admin')->group(function (): void {
    Route::get('platform/fee-promotions', [FeePromotionsController::class, 'index']);

    Route::middleware(EnsureSuperadmin::class)->group(function (): void {
        Route::patch('platform/fee-promotions', [FeePromotionsController::class, 'updateSettings']);
    });
});

/*
 * 2. THE MERCHANT'S OWN BANNER. Mounted on the web panel here and on the
 *    till app in routes/api/mobile.php — one controller, two doors. No
 *    permission gate: every account that may log in to a store may be told
 *    what that store is being charged.
 */
Route::prefix('merchant')->middleware('auth:merchant')->group(function (): void {
    Route::get('fee-promotion', FeePromotionBannerController::class);
});

/*
 * 3. THE PUBLIC LANDING. Unauthenticated, because the merchant landing page
 *    is the acquisition channel this feature exists for — and therefore
 *    strictly the OFFER: no merchant, no merchant's dates, nothing a stranger
 *    could learn about a particular store from (see the controller). 120/min
 *    per IP, the same ceiling the other unauthenticated reads wear; the
 *    answer itself comes from a 60-second cache.
 */
Route::get('public/fee-promotion', PublicFeePromotionController::class)
    ->middleware('throttle:120,1');
