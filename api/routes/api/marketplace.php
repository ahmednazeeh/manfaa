<?php

use App\Http\Controllers\Admin\MarketplaceKybController;
use App\Http\Controllers\Admin\OrderPaymentController;
use App\Http\Controllers\Customer\AddressController;
use App\Http\Controllers\Customer\CartController;
use App\Http\Controllers\Customer\MarketController;
use App\Http\Controllers\Customer\OrderController;
use App\Http\Controllers\Merchant\DeliveryRuleController;
use App\Http\Controllers\Merchant\MarketplaceEnrolmentController;
use App\Http\Controllers\Merchant\OrderController as MerchantOrderController;
use App\Http\Controllers\Merchant\ProductController;
use App\Http\Middleware\EnsureMerchantApproved;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

/*
 * MARKETPLACE (PLAN-marketplace.md). MP1: enrolment and KYB only — no
 * catalogue, no cart, no orders yet.
 *
 * EVERY route in this file wears `marketplace`, the kill switch. While the
 * platform setting is off they answer 404, so a product we have not launched
 * does not appear to exist — not to an old app build, not to a curled URL.
 * That is what makes the superadmin's "hide it everywhere" true rather than
 * merely visual (§10).
 */

Route::prefix('merchant/marketplace')
    ->middleware(['auth:merchant', 'marketplace'])
    ->group(function (): void {
        Route::get('enrolment', [MarketplaceEnrolmentController::class, 'show'])
            ->middleware('merchant.can:marketplace.manage');

        // Enrolment WRITES join every other write behind approval: a store
        // still in the wizard, or frozen for review, has no business
        // applying to sell online on top of it.
        Route::post('enrolment', [MarketplaceEnrolmentController::class, 'enrol'])
            ->middleware(['merchant.can:marketplace.manage', EnsureMerchantApproved::class]);

        Route::post('documents', [MarketplaceEnrolmentController::class, 'upload'])
            ->middleware(['merchant.can:marketplace.manage', EnsureMerchantApproved::class, 'throttle:20,1']);

        Route::get('documents/{document}', [MarketplaceEnrolmentController::class, 'download'])
            ->whereNumber('document')
            ->middleware('merchant.can:marketplace.manage');

        Route::post('submit', [MarketplaceEnrolmentController::class, 'submit'])
            ->middleware(['merchant.can:marketplace.manage', EnsureMerchantApproved::class]);
    });

/*
 * The catalogue (MP2). Definitions are the merchant's; listings belong to a
 * branch. Reads are open to anyone who may manage the shop; writes join the
 * approval gate like every other write.
 */
Route::prefix('merchant/marketplace')
    ->middleware(['auth:merchant', 'marketplace', 'merchant.can:marketplace.manage'])
    ->group(function (): void {
        Route::get('categories', [ProductController::class, 'categories']);
        Route::get('products', [ProductController::class, 'index']);

        Route::middleware(EnsureMerchantApproved::class)->group(function (): void {
            Route::post('products', [ProductController::class, 'store']);
            Route::patch('products/{product}', [ProductController::class, 'update'])->whereNumber('product');
            Route::delete('products/{product}', [ProductController::class, 'archive'])->whereNumber('product');

            // The operational half — instant by decision, and the reason a
            // shop can react to its own shelves without waiting for us.
            Route::put('products/{product}/listing', [ProductController::class, 'listing'])->whereNumber('product');

            Route::post('products/{product}/images', [ProductController::class, 'uploadImage'])
                ->whereNumber('product')->middleware('throttle:60,1');
            Route::delete('products/{product}/images/{image}', [ProductController::class, 'destroyImage'])
                ->whereNumber(['product', 'image']);
        });
    });

/*
 * A branch's delivery matrix (MP3). Instant, not gated: a fee is
 * operational, and unlike a store's name it is not a claim a shopper has
 * already relied on — the cart re-quotes at checkout.
 */
Route::prefix('merchant/marketplace')
    ->middleware(['auth:merchant', 'marketplace', 'merchant.can:marketplace.manage'])
    ->group(function (): void {
        Route::get('branches/{branch}/delivery', [DeliveryRuleController::class, 'index'])
            ->whereNumber('branch');

        Route::middleware(EnsureMerchantApproved::class)->group(function (): void {
            Route::put('branches/{branch}/delivery', [DeliveryRuleController::class, 'upsert'])
                ->whereNumber('branch');
            Route::delete('branches/{branch}/delivery/{zone}', [DeliveryRuleController::class, 'destroy'])
                ->whereNumber(['branch', 'zone']);
        });
    });

/*
 * Customer delivery addresses (MP3). Behind the kill switch like everything
 * else: an address book exists to be delivered to, and with no marketplace
 * there is nothing to deliver.
 */
Route::prefix('customer')
    ->middleware(['auth:customer', 'marketplace'])
    ->group(function (): void {
        Route::get('addresses', [AddressController::class, 'index']);
        Route::post('addresses', [AddressController::class, 'store'])->middleware('throttle:30,1');
        Route::patch('addresses/{address}', [AddressController::class, 'update'])->whereNumber('address');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address');
    });

/*
 * The Market (MP4). Browsing is OPEN — a shopper should be able to see what
 * a shop sells before signing in — while the cart needs an account, because
 * a basket belongs to somebody.
 */
Route::prefix('market')
    ->middleware(['marketplace', 'throttle:discovery'])
    ->group(function (): void {
        Route::get('branches', [MarketController::class, 'index']);
        Route::get('branches/{branch}', [MarketController::class, 'show'])->whereNumber('branch');
    });

Route::prefix('customer/cart')
    ->middleware(['auth:customer', 'marketplace'])
    ->group(function (): void {
        Route::get('/', [CartController::class, 'show']);
        Route::post('items', [CartController::class, 'add'])->middleware('throttle:120,1');
        Route::patch('items/{item}', [CartController::class, 'update'])->whereNumber('item');
        Route::delete('items/{item}', [CartController::class, 'destroy'])->whereNumber('item');
        Route::delete('/', [CartController::class, 'clear']);
    });

/*
 * Checkout and the customer's own orders (MP5). Payment is receipt-first —
 * the same shape merchants already settle with — so nothing is confirmed
 * until a human has seen the transfer.
 */
Route::prefix('customer')
    ->middleware(['auth:customer', 'marketplace'])
    ->group(function (): void {
        Route::get('payment-accounts', [OrderController::class, 'paymentAccounts']);
        Route::post('orders', [OrderController::class, 'place'])->middleware('throttle:20,1');
        Route::post('orders/{order}/receipt', [OrderController::class, 'uploadReceipt'])
            ->whereNumber('order')->middleware('throttle:20,1');
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
    });

/*
 * The shop's order queue (MP6). Quick actions: accept, reject, advance,
 * reduce. Behind the trading gate — a store that is not active has no
 * business fulfilling.
 */
Route::prefix('merchant/marketplace')
    ->middleware(['auth:merchant', 'marketplace', 'merchant.can:marketplace.manage'])
    ->group(function (): void {
        Route::get('orders', [MerchantOrderController::class, 'index']);
        Route::get('orders/{suborder}', [MerchantOrderController::class, 'show'])->whereNumber('suborder');

        Route::middleware(EnsureMerchantApproved::class.':'.EnsureMerchantApproved::TRADING)->group(function (): void {
            Route::post('orders/{suborder}/accept', [MerchantOrderController::class, 'accept'])->whereNumber('suborder');
            Route::post('orders/{suborder}/reject', [MerchantOrderController::class, 'reject'])->whereNumber('suborder');
            Route::post('orders/{suborder}/advance', [MerchantOrderController::class, 'advance'])->whereNumber('suborder');
            Route::post('orders/{suborder}/amend', [MerchantOrderController::class, 'amend'])->whereNumber('suborder');
        });
    });

Route::prefix('admin/marketplace')
    ->middleware(['auth:admin', 'marketplace'])
    ->group(function (): void {
        // Payment proof: reading is ordinary admin work, deciding is not.
        Route::get('payments', [OrderPaymentController::class, 'index']);
        Route::get('payments/{order}/receipt', [OrderPaymentController::class, 'receipt'])->whereNumber('order');

        Route::middleware(EnsureSuperadmin::class)->group(function (): void {
            Route::post('payments/{order}/verify', [OrderPaymentController::class, 'verify'])->whereNumber('order');
            Route::post('payments/{order}/refuse', [OrderPaymentController::class, 'refuse'])->whereNumber('order');
        });

        // Reading applications is ordinary admin work.
        Route::get('kyb', [MarketplaceKybController::class, 'index']);
        Route::get('kyb/{merchant}', [MarketplaceKybController::class, 'show'])->whereNumber('merchant');
        Route::get('kyb/{merchant}/documents/{document}', [MarketplaceKybController::class, 'download'])
            ->whereNumber(['merchant', 'document']);

        // Deciding is not. Same tier as store approval: letting a business
        // sell to the public on our platform is the same class of act.
        Route::middleware(EnsureSuperadmin::class)->group(function (): void {
            Route::post('kyb/{merchant}/approve', [MarketplaceKybController::class, 'approve'])->whereNumber('merchant');
            Route::post('kyb/{merchant}/reject', [MarketplaceKybController::class, 'reject'])->whereNumber('merchant');
        });
    });
