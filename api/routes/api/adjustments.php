<?php

use App\Http\Controllers\Admin\AdjustmentController;
use Illuminate\Support\Facades\Route;

// The admin adjustment (§6/§13 "corrections are adjustments, never edits";
// PLAN §1 backdated credits: "admin adjustment only"). The platform's
// correction of last resort, and the only in-app path to a mis-keyed
// backdated credit — which the merchant and their vendor are refused, by
// design, in every state.
//
// Admin guard only, and deliberately no merchant- or vendor-reachable
// counterpart: the whole point of the backdated decision is that the party
// who created the credit cannot take it back.
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::post('transactions/{id}/adjustments', [AdjustmentController::class, 'store'])->whereNumber('id');
});
