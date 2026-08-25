<?php

use App\Http\Controllers\Admin\SettlementController as AdminSettlementController;
use App\Http\Controllers\Admin\SettlementPaymentController;
use App\Http\Controllers\Admin\WalletController;
use App\Http\Controllers\Admin\WalletTopUpController as AdminWalletTopUpController;
use App\Http\Controllers\Merchant\PosWaiverController;
use App\Http\Controllers\Merchant\SettlementController as MerchantSettlementController;
use App\Http\Controllers\Merchant\TransferProgressController;
use App\Http\Controllers\Merchant\WalletTopUpController as MerchantWalletTopUpController;
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

    /*
     * The bank watch, as the screen that just uploaded a slip observes it
     * (owner, 2026-08-25). A pair, one per flow, answering the SAME shape
     * (App\Domain\Transfers\TransferProgress) so the settlement screen and
     * the wallet screen share one parser and cannot drift apart.
     *
     * They report `watching` as a FACT the server computes — auto-verify
     * on, the destination bank actually routed to a read profile, the
     * window still open, the row still pending — and a machine `reason`
     * when it is false, so a client never has to guess and never animates
     * progress over a transfer nobody is watching.
     *
     * Permissioned exactly like the parent read each one belongs to:
     * settlements.view for a batch's payment, wallet.view for a top-up.
     *
     * throttle:120,1, per route (ThrottlePerRoute gives each declaration
     * its own bucket): the client polls every 5 seconds for a 15-minute
     * verify window, which is 12 requests a minute. 120 leaves an order of
     * magnitude of headroom — a merchant with the panel and the app open at
     * once, a retry storm after a dropped connection, or a shortened poll
     * interval — while still bounding a runaway client. Both reads are a
     * handful of primary-key lookups, so the ceiling is about protecting
     * the merchant from their own client, not the database from the read.
     */
    Route::get('settlements/{id}/payment-progress', [TransferProgressController::class, 'settlement'])
        ->whereNumber('id')
        ->middleware(['merchant.can:settlements.view', 'throttle:120,1']);

    Route::get('wallet/top-ups/{id}/progress', [TransferProgressController::class, 'walletTopUp'])
        ->whereNumber('id')
        ->middleware(['merchant.can:wallet.view', 'throttle:120,1']);

    Route::post('settlements', [MerchantSettlementController::class, 'store'])
        ->middleware(['merchant.can:settlements.create', EnsureMerchantApproved::class]);

    Route::post('settlements/wallet', [MerchantSettlementController::class, 'walletSettle'])
        ->middleware(['merchant.can:wallet.settle', EnsureMerchantApproved::class]);

    // Funding the wallet by bank transfer (owner, 2026-08-24): the same
    // receipt-first act as a settlement — account, slip, optional reference
    // — creating a pending claim the bank-history verifier or an admin
    // turns into a credit. Its own permission; approved stores only, as
    // for every other claim of a real transfer.
    // Throttled: each claim stores a slip, runs OCR and polls the bank for
    // the whole verify window, and WalletTopUps::MAX_PENDING bounds how
    // many may wait at once.
    Route::post('wallet/top-ups', [MerchantWalletTopUpController::class, 'store'])
        ->middleware(['merchant.can:wallet.top_up', EnsureMerchantApproved::class, 'throttle:5,1']);

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

    // The wallet top-up queue (owner, 2026-08-24): the fallback for claims
    // the bank-history verifier could not match. Any admin, like the
    // settlement payment match above — reconciling against a statement is
    // queue work, not a superadmin lever.
    Route::get('wallet-top-ups', [AdminWalletTopUpController::class, 'index']);
    Route::get('wallet-top-ups/{id}/slip', [AdminWalletTopUpController::class, 'slip'])->whereNumber('id');
    Route::post('wallet-top-ups/{id}/match', [AdminWalletTopUpController::class, 'match'])->whereNumber('id');
    Route::post('wallet-top-ups/{id}/reject', [AdminWalletTopUpController::class, 'reject'])->whereNumber('id');
});
