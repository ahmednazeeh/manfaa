<?php

use App\Http\Controllers\Admin\SettlementController as AdminSettlementController;
use App\Http\Controllers\Admin\SettlementPaymentController;
use App\Http\Controllers\Admin\WalletController;
use App\Http\Controllers\Merchant\PosWaiverController;
use App\Http\Controllers\Merchant\SettlementController as MerchantSettlementController;
use App\Http\Middleware\EnsureMerchantApproved;
use Illuminate\Support\Facades\Route;

// Settlement domain (§6, §7, §12 Phase 1), receipt-first (PLAN §1): the
// merchant previews what a selection costs, transfers at their bank, then
// SUBMITS the slip + bank reference — the single act that creates a
// settlement, landing it directly in payment_review. There is no
// draft-then-submit pair and no merchant route that reaches
// awaiting_payment: a settlement without a receipt cannot be created through
// this API at all.
//
// Every merchant {id} route resolves through the authenticated merchant's
// own relations — another merchant's settlement is indistinguishable from a
// missing one. Every settlement MUTATION additionally needs an APPROVED
// store (EnsureMerchantApproved): submitting freezes lines irreversibly and
// claims a real bank transfer, so it belongs to a store that has actually
// passed review. Reads and the preview seed to every role — the preview
// claims nothing, and the till screen shows what is owed.
//
// `outstanding` answers to settlements.view: it is the unsettled-lines read
// model the settlement builder is assembled from, not a separate surface.
// The wallet is its own pair, because spending a wallet balance settles
// real money without a bank transfer ever being made.
Route::prefix('merchant')->middleware('auth:merchant')->group(function () {
    // The POS-fee waiver card (owner, 2026-08-23): commercial standing,
    // same permission as the outstanding board it derives from.
    Route::get('pos-waiver', [PosWaiverController::class, 'show'])
        ->middleware('merchant.can:settlements.view');

    Route::get('outstanding', [MerchantSettlementController::class, 'outstanding'])
        ->middleware('merchant.can:settlements.view');

    Route::get('wallet', [MerchantSettlementController::class, 'wallet'])
        ->middleware('merchant.can:wallet.view');

    Route::get('settlements', [MerchantSettlementController::class, 'index'])
        ->middleware('merchant.can:settlements.view');

    Route::get('settlements/preview', [MerchantSettlementController::class, 'preview'])
        ->middleware('merchant.can:settlements.preview');

    Route::get('settlements/{id}', [MerchantSettlementController::class, 'show'])
        ->whereNumber('id')
        ->middleware('merchant.can:settlements.view');

    Route::post('settlements', [MerchantSettlementController::class, 'store'])
        ->middleware(['merchant.can:settlements.create', EnsureMerchantApproved::class]);

    Route::post('settlements/wallet', [MerchantSettlementController::class, 'walletSettle'])
        ->middleware(['merchant.can:wallet.settle', EnsureMerchantApproved::class]);

    // A further transfer against a batch still owed money (§7 partial
    // payments, or an admin-built fallback batch) — also receipt-bearing.
    Route::post('settlements/{id}/receipts', [MerchantSettlementController::class, 'storeReceipt'])
        ->whereNumber('id')
        ->middleware(['merchant.can:settlements.receipt_add', EnsureMerchantApproved::class]);
});

Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('settlements', [AdminSettlementController::class, 'index']);
    Route::get('settlements/{id}', [AdminSettlementController::class, 'show'])->whereNumber('id');
    // Slips are private (storage/app/slips — no disk URL, not served): this
    // authenticated stream is the only way one is ever read.
    Route::get('settlements/{id}/slip', [AdminSettlementController::class, 'slip'])->whereNumber('id');
    Route::post('settlements/{id}/payments', [AdminSettlementController::class, 'storePayment'])->whereNumber('id');
    Route::post('settlements/{id}/reject', [AdminSettlementController::class, 'reject'])->whereNumber('id');
    Route::post('payments/{id}/match', [SettlementPaymentController::class, 'match'])->whereNumber('id');
    Route::post('merchants/{merchant}/wallet/top-ups', [WalletController::class, 'storeTopUp'])->whereNumber('merchant');
});
