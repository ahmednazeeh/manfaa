<?php

use App\Http\Controllers\Admin\MerchantSettlementController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

/*
 * Merchant Settlements — its OWN menu (owner requirement).
 *
 * Deliberately not merged with the cashback settlements screen: there a
 * merchant pays us, here we pay them, and one screen showing both directions
 * is a screen nobody can check.
 *
 * Behind the marketplace switch, because there is nothing to settle without
 * marketplace orders.
 */
Route::prefix('admin/merchant-settlements')
    ->middleware(['auth:admin', 'marketplace'])
    ->group(function (): void {
        Route::get('/', [MerchantSettlementController::class, 'index']);
        Route::get('{batch}', [MerchantSettlementController::class, 'show'])->whereNumber('batch');
        Route::get('{batch}/export', [MerchantSettlementController::class, 'export'])->whereNumber('batch');

        // Building, approving and paying are superadmin — the same tier the
        // customer payout run sits behind.
        Route::middleware(EnsureSuperadmin::class)->group(function (): void {
            Route::post('/', [MerchantSettlementController::class, 'store']);
            Route::post('{batch}/approve', [MerchantSettlementController::class, 'approve'])->whereNumber('batch');
            Route::post('{batch}/import', [MerchantSettlementController::class, 'import'])->whereNumber('batch');
            Route::post('{batch}/send', [MerchantSettlementController::class, 'sendBatch'])->whereNumber('batch');
            Route::post('{batch}/items/{item}/send', [MerchantSettlementController::class, 'send'])
                ->whereNumber(['batch', 'item']);
            Route::post('{batch}/cancel', [MerchantSettlementController::class, 'cancel'])->whereNumber('batch');
        });
    });
