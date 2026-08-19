<?php

use App\Http\Controllers\Admin\PendingPaymentsController;
use App\Http\Controllers\Admin\TransferSettingsController;
use App\Http\Controllers\Customer\WalletController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

/*
 * The customer wallet and the queue for money leaving the platform
 * (PLAN-marketplace.md §21).
 *
 * DELIBERATELY NOT behind the marketplace kill switch. A refund already paid
 * into a wallet has to stay visible and withdrawable even if the marketplace
 * is switched off tomorrow — money we owe outlives a feature flag.
 */
Route::prefix('customer')->middleware('auth:customer')->group(function (): void {
    Route::get('wallet', [WalletController::class, 'show']);
    Route::post('wallet/withdrawals', [WalletController::class, 'withdraw'])->middleware('throttle:10,1');
});

Route::prefix('admin')->middleware('auth:admin')->group(function (): void {
    // Reading what we owe is ordinary admin work.
    Route::get('pending-payments', [PendingPaymentsController::class, 'index']);
    Route::get('transfer-settings', [TransferSettingsController::class, 'index']);

    // Moving money is not.
    Route::middleware(EnsureSuperadmin::class)->group(function (): void {
        Route::post('pending-payments/{payout}/send', [PendingPaymentsController::class, 'send'])->whereNumber('payout');
        Route::post('pending-payments/{payout}/mark-sent', [PendingPaymentsController::class, 'markSent'])->whereNumber('payout');
        Route::post('pending-payments/{payout}/cancel', [PendingPaymentsController::class, 'cancel'])->whereNumber('payout');

        Route::patch('transfer-settings', [TransferSettingsController::class, 'updateSettings']);
        Route::patch('transfer-settings/profiles/{profile}', [TransferSettingsController::class, 'updateProfile'])->whereNumber('profile');
        Route::patch('transfer-settings/watched-accounts/{account}', [TransferSettingsController::class, 'updateWatchedAccount'])->whereNumber('account');
    });
});
