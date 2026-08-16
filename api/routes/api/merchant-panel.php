<?php

use App\Http\Controllers\Merchant\TransactionCorrectionController;
use App\Http\Controllers\Merchant\TransactionsController;
use App\Http\Middleware\EnsureMerchantApproved;
use Illuminate\Support\Facades\Route;

// Merchant panel read models (§10): the filterable transactions table.
// Auth-scoped to the merchant guard; state filtering validates against the
// §6 transaction state machine.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('transactions', [TransactionsController::class, 'index'])
        ->middleware('merchant.can:transactions.view');

    // Correcting a sale the till got wrong, while the merchant's own
    // validation window is still open, on a trading store: keying a sale in
    // is staff work, but taking cashback back off a customer who has
    // already been told they earned it is not. Amend and cancel are
    // separate permissions — one fixes a number, the other takes the whole
    // sale away — and both seed to Manager and above.
    //
    // Permission first, approval second, here and everywhere: a user who
    // may not correct sales must be refused before the response can tell
    // them whether the store is still trading.
    Route::patch('transactions/{id}', [TransactionCorrectionController::class, 'amend'])
        ->whereNumber('id')
        ->middleware(['merchant.can:transactions.amend', EnsureMerchantApproved::class.':trading']);

    Route::post('transactions/{id}/cancel', [TransactionCorrectionController::class, 'cancel'])
        ->whereNumber('id')
        ->middleware(['merchant.can:transactions.cancel', EnsureMerchantApproved::class.':trading']);
});
