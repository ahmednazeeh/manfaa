<?php

use App\Http\Controllers\Admin\HoldsController;
use Illuminate\Support\Facades\Route;

// The hold-review queue (PLAN §13b task #22). on_hold is fraud/dispute review
// only since task #23 — no automated path parks a row here — so every row in
// this queue is waiting on a human, and every route below is that human's.
//
// Admin guard only, and with no merchant- or vendor-facing counterpart by
// design: a merchant must not learn which of their sales is under fraud
// review, and must certainly not be able to clear it.
//
// Any admin may review holds. Unlike store approval (superadmin) this is
// day-to-day operational work, and a hold left undecided is itself a harm —
// the customer's cashback sits Pending and the merchant's clock does not run.
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('holds', [HoldsController::class, 'index']);
    Route::post('holds/{transaction}/release', [HoldsController::class, 'release'])->whereNumber('transaction');
    Route::post('holds/{transaction}/reject', [HoldsController::class, 'reject'])->whereNumber('transaction');
});
