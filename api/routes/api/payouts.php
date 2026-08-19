<?php

use App\Http\Controllers\Admin\PayoutBatchController;
use Illuminate\Support\Facades\Route;

// Payout domain (§6, §12 Phase 1): batches built to a chosen cutoff date,
// single approval, transfer sheet out and back, failure re-queue. Admin-only
// — enforcement is by guard, never by hiding routes.
Route::prefix('admin/payout-batches')->middleware('auth:admin')->group(function () {
    Route::get('/', [PayoutBatchController::class, 'index']);
    Route::post('/', [PayoutBatchController::class, 'store']);
    Route::get('{batch}', [PayoutBatchController::class, 'show']);
    Route::post('{batch}/approve', [PayoutBatchController::class, 'approve']);
    // POST, deliberately: export mutates state (approved → processing, items
    // → sent). As a GET it would be triggered by link prefetch and reachable
    // cross-site with a SameSite=Lax admin cookie, CSRF-token-free.
    Route::post('{batch}/export', [PayoutBatchController::class, 'export']);
    Route::post('{batch}/import', [PayoutBatchController::class, 'import']);
    Route::post('{batch}/settle-all', [PayoutBatchController::class, 'settleAll']);
    // The third road to the bank, beside export/import and per-row mark-paid.
    Route::post('{batch}/send-via-api', [PayoutBatchController::class, 'sendViaApi']);
    // Scoped bindings, so an item id from another batch resolves to a 404
    // rather than settling a row this batch does not own.
    Route::post('{batch}/items/{item}/mark-paid', [PayoutBatchController::class, 'markPaid'])->scopeBindings();
    Route::post('{batch}/items/{item}/mark-failed', [PayoutBatchController::class, 'markFailed'])->scopeBindings();
    Route::post('{batch}/cancel', [PayoutBatchController::class, 'cancel']);
});
