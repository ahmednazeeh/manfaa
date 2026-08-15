<?php

use App\Http\Controllers\Merchant\TransactionCorrectionController;
use App\Http\Controllers\Merchant\TransactionsController;
use App\Http\Middleware\EnsureMerchantApproved;
use Illuminate\Support\Facades\Route;

// Merchant panel read models (§10): the filterable transactions table.
// Auth-scoped to the merchant guard; state filtering validates against the
// §6 transaction state machine.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('transactions', [TransactionsController::class, 'index']);

    // Correcting a sale the till got wrong, while the merchant's own
    // validation window is still open. MANAGER and above, and a trading
    // store: keying a sale in is staff work, but taking cashback back off a
    // customer who has already been told they earned it is not.
    Route::middleware(['merchant.role:manager', EnsureMerchantApproved::class.':trading'])->group(function () {
        Route::patch('transactions/{id}', [TransactionCorrectionController::class, 'amend'])->whereNumber('id');
        Route::post('transactions/{id}/cancel', [TransactionCorrectionController::class, 'cancel'])->whereNumber('id');
    });
});
