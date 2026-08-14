<?php

use App\Http\Controllers\Merchant\TransactionsController;
use Illuminate\Support\Facades\Route;

// Merchant panel read models (§10): the filterable transactions table.
// Auth-scoped to the merchant guard; state filtering validates against the
// §6 transaction state machine.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    Route::get('transactions', [TransactionsController::class, 'index']);
});
