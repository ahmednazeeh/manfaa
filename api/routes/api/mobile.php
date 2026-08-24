<?php

declare(strict_types=1);

use App\Http\Controllers\Customer\ActivityController;
use App\Http\Controllers\Customer\AddressController;
use App\Http\Controllers\Customer\AvatarController;
use App\Http\Controllers\Customer\CartController;
use App\Http\Controllers\Customer\FavouriteController;
use App\Http\Controllers\Customer\MarketSearchController;
use App\Http\Controllers\Customer\OrderController as MarketOrderController;
use App\Http\Controllers\Customer\ReferralsController;
use App\Http\Controllers\Customer\WalletController;
use App\Http\Controllers\Devices\CustomerDevicesController;
use App\Http\Controllers\Devices\CustomerPushTokenController;
use App\Http\Controllers\Devices\MerchantDevicesController;
use App\Http\Controllers\Devices\MerchantPushTokenController;
use App\Http\Controllers\Merchant\BankAccountController;
use App\Http\Controllers\Merchant\BranchesController;
use App\Http\Controllers\Merchant\CustomerLookupController;
use App\Http\Controllers\Merchant\MarketplaceEnrolmentController;
use App\Http\Controllers\Merchant\OrderController as MerchantOrderController;
use App\Http\Controllers\Merchant\PreferencesController;
use App\Http\Controllers\Merchant\ProductCategoriesController;
use App\Http\Controllers\Merchant\ProductController;
use App\Http\Controllers\Merchant\ProfileController;
use App\Http\Controllers\Merchant\PromotionController;
use App\Http\Controllers\Merchant\RateController;
use App\Http\Controllers\Merchant\ReverseGeocodeController;
use App\Http\Controllers\Merchant\RolesController;
use App\Http\Controllers\Merchant\SettlementController;
use App\Http\Controllers\Merchant\SetupController;
use App\Http\Controllers\Merchant\StaffController;
use App\Http\Controllers\Merchant\TransactionCorrectionController;
use App\Http\Controllers\Merchant\WalletTopUpController;
use App\Http\Controllers\Mobile\ConfigController;
use App\Http\Controllers\Mobile\CreditController;
use App\Http\Controllers\Mobile\CustomerOtpController;
use App\Http\Controllers\Mobile\CustomerProfileController;
use App\Http\Controllers\Mobile\CustomerTokenController;
use App\Http\Controllers\Mobile\HomeController;
use App\Http\Controllers\Mobile\MerchantSignupController;
use App\Http\Controllers\Mobile\MerchantTokenController;
use App\Http\Controllers\Mobile\PayoutAccountController;
use App\Http\Controllers\Mobile\PayoutsController;
use App\Http\Controllers\Mobile\SessionController;
use App\Http\Controllers\Mobile\TransactionsController;
use App\Http\Middleware\EnsureMerchantApproved;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\NormalisesMobileErrors;
use App\Http\Middleware\RecordsCustomerDevice;
use Illuminate\Support\Facades\Route;

/**
 * The mobile API (PLAN-mobile-api.md). Serves the two Flutter apps and
 * nothing else.
 *
 * WHY A SEPARATE TREE, and not versioned copies of the panel routes: an
 * installed app three months old still calls these exact paths, so they must
 * be freezable — while /api/customer/* and /api/merchant/* deploy in lockstep
 * with the panels and must stay free to change. The shapes differ too: mobile
 * wants aggregates and cursors, the panels want per-resource reads and page
 * numbers.
 *
 * WHAT IS NOT DUPLICATED: any business rule. Controllers here are thin and
 * call the same domain services as the web tree — BalanceQuery, CreditRecorder,
 * SettlementBuilder. Two HTTP surfaces, one domain.
 *
 * EVERY authenticated route carries BOTH `auth:sanctum` and `mobile.token`.
 * auth:sanctum resolves the bearer token; mobile.token is what proves it is
 * the RIGHT KIND of token — see EnsureMobileToken, and do not add a route
 * here that skips it.
 */
Route::prefix('mobile/v1')
    // OUTERMOST on the tree: guarantees one error shape leaves it, including
    // for refusals that middleware RETURNS rather than throws (M2).
    ->middleware(NormalisesMobileErrors::class)
    ->group(function (): void {

        // Read on launch, before anyone signs in — the version gate has to reach
        // a build too old to authenticate. Conditional (ETag): unchanged config
        // costs a 304, not a payload.
        Route::get('config', ConfigController::class)->middleware('throttle:60,1,mobile-config');

        /*
         * Sign-in. Unauthenticated by definition, and throttled at the same 5/min
         * as the web logins — the tighter of the two limits a credential-stuffing
         * attempt would meet.
         */
        // The third throttle argument is a KEY PREFIX. Without one, Laravel keys
        // an unnamed throttle on sha1(domain|ip) alone — no route, no method —
        // so every unnamed throttle in this repo shares ONE counter per IP, and
        // a visitor whose browser just painted a storefront (logos.php allows
        // 240/min) would arrive here with the bucket already spent and be
        // refused sign-in at 5. Each endpoint gets its own bucket.
        Route::post('customer/auth/token', [CustomerTokenController::class, 'store'])
            ->middleware('throttle:5,1,mobile-customer-signin');

        /*
         * Passwordless OTP sign-in/signup (R1) — the customer app's real
         * flow; the password endpoint above remains for accounts that hold
         * one. request carries its own dual limiter (3/h per phone + 10/h
         * per IP, SHARED with the web signup so the SMS budget cannot be
         * doubled by alternating surfaces); these route throttles are the
         * coarse backstop. The OTP attempt cap itself lives in OtpService,
         * inside a row lock.
         */
        Route::post('customer/auth/otp/request', [CustomerOtpController::class, 'request'])
            ->middleware('throttle:30,1,mobile-otp-request');
        Route::post('customer/auth/otp/verify', [CustomerOtpController::class, 'verify'])
            ->middleware('throttle:10,1,mobile-otp-verify');
        Route::post('customer/auth/register', [CustomerOtpController::class, 'register'])
            ->middleware('throttle:10,1,mobile-otp-register');

        Route::post('merchant/auth/token', [MerchantTokenController::class, 'store'])
            ->middleware('throttle:5,1,mobile-merchant-signin');

        /*
         * Store self-signup from the app (merchant app MR1) — the web
         * signup flow (routes/api/merchant-signup.php) on the mobile
         * surface: PUBLIC by definition, same MerchantOtpService, same
         * shared SMS budget (MerchantOtpRequestLimiter inside the
         * controller); these route throttles are the coarse backstop at the
         * web flow's numbers. register mints a merchant token instead of a
         * session — the app lands signed in as the draft store's owner.
         */
        Route::prefix('merchant/signup')->group(function (): void {
            Route::post('request-otp', [MerchantSignupController::class, 'requestOtp'])
                ->middleware('throttle:30,1,mobile-merchant-otp-request');
            Route::post('verify-otp', [MerchantSignupController::class, 'verifyOtp'])
                ->middleware('throttle:10,1,mobile-merchant-otp-verify');
            Route::post('register', [MerchantSignupController::class, 'register'])
                ->middleware('throttle:10,1,mobile-merchant-otp-register');
        });

        /*
         * Customer app.
         *
         * RecordsCustomerDevice rides EVERY authed customer route: the app
         * sends X-Device-Id (+ optional X-Device-Platform) and the
         * self-referral defence records the keyed hash — O(one cache hit)
         * on repeat requests, and the first request after signup covers
         * signup itself.
         */
        Route::middleware(['auth:sanctum', 'mobile.token:customer', RecordsCustomerDevice::class])
            ->prefix('customer')
            ->group(function (): void {
                Route::delete('auth/token', [CustomerTokenController::class, 'destroy']);
                Route::delete('auth/tokens', [CustomerTokenController::class, 'destroyAll']);

                // Fresh identity on launch/resume.
                Route::get('me', [SessionController::class, 'customer']);

                // The whole first screen in ONE request, conditional so a
                // resume with nothing changed costs a 304 (M3).
                Route::get('home', [HomeController::class, 'customer']);

                // Cursor-paged: this list grows at the TOP, and offset paging
                // duplicates and skips rows while sales are landing.
                Route::get('transactions', [TransactionsController::class, 'customer']);

                /*
                 * MARKETPLACE (PLAN-marketplace.md).
                 *
                 * These existed on the website and NOT here, which meant the
                 * app could browse the Market — that endpoint is public —
                 * and then 404 on everything past "add to cart". The screens
                 * shipped without their doors.
                 *
                 * Same controllers as the website, same middleware, same
                 * rules: one behaviour, two ways in. The marketplace switch
                 * gates all of it except the wallet, because money we owe
                 * outlives a feature flag.
                 */
                Route::middleware('marketplace')->group(function (): void {
                    // Where to deliver. An address book exists to be
                    // delivered to, so it lives behind the same switch.
                    Route::get('addresses', [AddressController::class, 'index']);
                    Route::post('addresses', [AddressController::class, 'store'])
                        ->middleware('throttle:30,1');
                    Route::patch('addresses/{address}', [AddressController::class, 'update'])
                        ->whereNumber('address');
                    Route::delete('addresses/{address}', [AddressController::class, 'destroy'])
                        ->whereNumber('address');

                    // The basket belongs to somebody, hence the token.
                    Route::get('cart', [CartController::class, 'show']);
                    Route::post('cart/items', [CartController::class, 'add'])
                        ->middleware('throttle:120,1');
                    Route::patch('cart/items/{item}', [CartController::class, 'update'])
                        ->whereNumber('item');
                    Route::delete('cart/items/{item}', [CartController::class, 'destroy'])
                        ->whereNumber('item');
                    Route::delete('cart', [CartController::class, 'clear']);

                    // Checkout is receipt-first: nothing is confirmed until
                    // the money is seen.
                    Route::get('payment-accounts', [MarketOrderController::class, 'paymentAccounts']);
                    Route::post('orders', [MarketOrderController::class, 'place'])
                        ->middleware('throttle:20,1');
                    Route::post('orders/{order}/receipt', [MarketOrderController::class, 'uploadReceipt'])
                        ->whereNumber('order')->middleware('throttle:20,1');

                    // The list the app had no way to reach: an order was
                    // visible once, immediately after checkout, and never
                    // again.
                    Route::get('orders', [MarketOrderController::class, 'index']);
                    Route::get('orders/{order}', [MarketOrderController::class, 'show'])
                        ->whereNumber('order');

                    // One timeline: marketplace orders AND cashback.
                    Route::get('activity', [ActivityController::class, 'index']);

                    Route::get('search', MarketSearchController::class);
                    Route::get('products/{branchProduct}', [MarketSearchController::class, 'show'])
                        ->whereNumber('branchProduct');
                    Route::get('favourites', [FavouriteController::class, 'index']);
                    Route::post('favourites/{branch}', [FavouriteController::class, 'toggle'])
                        ->whereNumber('branch')->middleware('throttle:120,1');
                });

                /*
                 * The wallet, deliberately OUTSIDE the switch above. A
                 * refund already paid in must stay visible and withdrawable
                 * even if the marketplace is switched off tomorrow.
                 */
                Route::get('wallet', [WalletController::class, 'show']);
                Route::post('wallet/withdrawals', [WalletController::class, 'withdraw'])
                    ->middleware('throttle:10,1');

                // Referrals (owner, 2026-08-23): the customer's code, the
                // programme's figures and each invited friend's progress.
                // The SAME controller the web mounts (routes/api/referrals.php).
                Route::get('referrals', [ReferralsController::class, 'show']);

                // Payout history (R3): the money that reached the bank, and
                // what each transfer covered. Same rules as the web —
                // pending excluded, failed included, own rows only.
                Route::get('payouts', [PayoutsController::class, 'index']);
                Route::get('payouts/{id}', [PayoutsController::class, 'show'])->whereNumber('id');

                // Payout account (R5). Reading and clearing move no money to a
                // new destination and carry no gate; CHANGING the bank the
                // platform pays to demands a fresh code to the number on file
                // (the SIM-swap / stolen-token mitigation). The otp request
                // carries its own shared SMS budget; the route throttle is a
                // coarse backstop.
                // Profile picture — the SAME controller the website mounts
                // (routes/api/customer.php): one rule set, two doors.
                // Reading the picture is the public capability URL in
                // customer.php — the app's image loader sends no bearer
                // token, so the URL itself must carry the authorisation.
                // Correcting the Thaana name a model wrote at registration.
                // Throttled like the other self-service writes: it is a typo
                // fix, not something anyone does in a loop.
                Route::patch('profile', [CustomerProfileController::class, 'update'])
                    ->middleware('throttle:20,1');

                Route::post('avatar', [AvatarController::class, 'store'])
                    ->middleware('throttle:10,1,mobile-avatar-upload');
                Route::delete('avatar', [AvatarController::class, 'destroy'])
                    ->middleware('throttle:10,1,mobile-avatar-remove');

                Route::get('payout-account', [PayoutAccountController::class, 'show']);
                Route::post('payout-account/otp', [PayoutAccountController::class, 'requestOtp'])
                    ->middleware('throttle:10,1,mobile-payout-otp');
                Route::put('payout-account', [PayoutAccountController::class, 'update'])
                    ->middleware('throttle:10,1,mobile-payout-update');

                // Also mounted on the WEBSITE (routes/api/customer.php). See the
                // DevicesController docblock: a device list reachable only from a
                // live token cannot cut off the phone that was lost.
                Route::get('devices', [CustomerDevicesController::class, 'index']);
                Route::delete('devices/{id}', [CustomerDevicesController::class, 'destroy'])->whereNumber('id');
                Route::delete('devices', [CustomerDevicesController::class, 'destroyAll']);

                // Push registration. Bound to THIS auth token, so it dies
                // with it — see PushTokenController (M4).
                Route::put('push-token', [CustomerPushTokenController::class, 'update']);
                Route::delete('push-token', [CustomerPushTokenController::class, 'destroy']);
            });

        /*
         * Merchant app.
         */
        Route::middleware(['auth:sanctum', 'mobile.token:merchant'])
            ->prefix('merchant')
            ->group(function (): void {
                Route::delete('auth/token', [MerchantTokenController::class, 'destroy']);
                Route::delete('auth/tokens', [MerchantTokenController::class, 'destroyAll']);

                // Fresh identity AND fresh permissions. Without this the till's
                // navigation was frozen for the 90-day life of the token.
                Route::get('me', [SessionController::class, 'merchant']);

                // Home stays open to every till — today's takings and the
                // store's trading status are what a cashier is there for —
                // but the two settlement-gated blocks inside it are withheld
                // unless the account may see them (see HomeController).
                Route::get('home', [HomeController::class, 'merchant']);

                // The SAME gate the panel applies (routes/api/merchant-panel.php:13).
                // Dropping it here would hand a credits-only cashier the full
                // history: invoice numbers, frozen rates, fees and GST.
                Route::get('transactions', [TransactionsController::class, 'merchant'])
                    ->middleware('merchant.can:transactions.view');

                /*
                 * MARKETPLACE — the shop's order queue and shelf
                 * (PLAN-marketplace.md §4.1, §4.2).
                 *
                 * Missing until 2026-08-19, exactly as the customer app's
                 * were: the panel had these and the app had nothing, so a
                 * shopkeeper could not work an order from their phone.
                 *
                 * Same controllers and same gates as the panel — one
                 * behaviour, two doors. `marketplace` is the platform
                 * switch, `merchant.can:marketplace.manage` the staff
                 * permission, EnsureMerchantTrading the shop's own state: a
                 * store that is not active has no business fulfilling.
                 */
                Route::prefix('marketplace')
                    // EnsureMerchantApproved matches the web routes exactly
                    // (routes/api/marketplace.php): a store that is not
                    // trading has no business working a queue or a shelf,
                    // and the app was the one door that did not say so.
                    ->middleware([
                        'marketplace',
                        'merchant.can:marketplace.manage',
                        EnsureMerchantApproved::class,
                    ])
                    ->group(function (): void {
                        // Whether this store sells at all, which is what the
                        // app needs before it draws an Orders tab.
                        Route::get('enrolment', [MarketplaceEnrolmentController::class, 'show']);
                        // Applying, from the phone. A shopkeeper who cannot
                        // find how to join cannot join — pointing at a
                        // desktop portal is an answer, not a door.
                        Route::post('enrolment', [MarketplaceEnrolmentController::class, 'enrol'])
                            ->middleware('merchant.can:marketplace.enrol');
                        Route::post('documents', [MarketplaceEnrolmentController::class, 'upload'])
                            ->middleware(['merchant.can:marketplace.enrol', 'throttle:20,1']);
                        Route::post('submit', [MarketplaceEnrolmentController::class, 'submit'])
                            ->middleware(['merchant.can:marketplace.enrol', 'throttle:20,1']);

                        Route::get('orders', [MerchantOrderController::class, 'index']);
                        Route::get('orders/{suborder}', [MerchantOrderController::class, 'show'])
                            ->whereNumber('suborder');
                        Route::post('orders/{suborder}/accept', [MerchantOrderController::class, 'accept'])
                            ->whereNumber('suborder');
                        Route::post('orders/{suborder}/reject', [MerchantOrderController::class, 'reject'])
                            ->whereNumber('suborder');
                        Route::post('orders/{suborder}/advance', [MerchantOrderController::class, 'advance'])
                            ->whereNumber('suborder');
                        // Editing while picking (§2.7): drop a line the shelf
                        // cannot fill, refund the difference.
                        Route::post('orders/{suborder}/amend', [MerchantOrderController::class, 'amend'])
                            ->whereNumber('suborder');

                        // Reading the shelf, and the QUICK edits the ref
                        // allows: price, stock, visibility. Catalogue work
                        // proper stays on desktop, and the screen says so.
                        Route::get('products', [ProductController::class, 'index']);
                        Route::put('products/{product}/listing', [ProductController::class, 'listing'])
                            ->whereNumber('product');
                    });

                /*
                 * The till void (merchant app MR2) — the SAME two corrections
                 * the panel offers (routes/api/merchant-panel.php), same
                 * controller, same gates in the same order: permission FIRST,
                 * so a user who may not correct sales is refused before the
                 * response can tell them whether the store is still trading,
                 * then EnsureMerchantApproved:trading. The controller's
                 * {message, code} refusals (409 not_amendable_state /
                 * backdated_irreversible, 422 sale_below_eligible /
                 * lines_sum_mismatch) leave through NormalisesMobileErrors as
                 * the one mobile envelope.
                 */
                Route::patch('transactions/{id}', [TransactionCorrectionController::class, 'amend'])
                    ->whereNumber('id')
                    ->middleware(['merchant.can:transactions.amend', EnsureMerchantApproved::class.':trading']);

                Route::post('transactions/{id}/cancel', [TransactionCorrectionController::class, 'cancel'])
                    ->whereNumber('id')
                    ->middleware(['merchant.can:transactions.cancel', EnsureMerchantApproved::class.':trading']);

                /*
                 * The till's whole reason to be signed in.
                 *
                 * Idempotency-Key REQUIRED: a till that loses signal queues
                 * its sales and drains them on reconnect, and a request that
                 * timed out after the server committed must replay the
                 * original response rather than book a second sale.
                 *
                 * 60/min rather than the panel's 30 — a draining offline
                 * queue is a burst by definition, and a human at a till is
                 * not. Still bounded: manual entry is the exposed fraud
                 * surface (§11).
                 *
                 * A NAMED limiter (AppServiceProvider), not `throttle:60,1`.
                 * The inline form keys on sha1(getAuthIdentifier()) with no
                 * model discriminator, and ThrottleRequests sorts ABOVE
                 * EnsureMobileToken in the middleware priority — so a
                 * customer token spending 60 rejected requests here would
                 * lock out the merchant user who happens to share its
                 * numeric id at an unrelated store.
                 */
                Route::post('credits', [CreditController::class, 'store'])
                    ->middleware([
                        'merchant.can:credits.create',
                        IdempotencyMiddleware::class,
                        'throttle:mobile-credits',
                    ]);

                /*
                 * The credit screen's name-confirmation (§11) — the web
                 * lookup unchanged (routes/api/merchant-settings.php): same
                 * permission (customers.lookup seeds to every role — posting
                 * credits is till work), and the same 30/min-per-user
                 * throttle, as a NAMED limiter rather than the web's inline
                 * `throttle:30,1` — see the credits route above for the
                 * cross-audience id-collision the inline form invites. The
                 * per-MERCHANT daily miss budget lives inside the controller
                 * and is deliberately SHARED with the web surface, so
                 * alternating surfaces cannot double the enumeration
                 * allowance.
                 */
                Route::get('customers/lookup', CustomerLookupController::class)
                    ->middleware(['merchant.can:customers.lookup', 'throttle:mobile-lookup']);

                // The credit screen's live estimate source: the standing rate
                // plus any pending (§7 next-midnight decrease) window, shaped
                // exactly as the web panel reads it (routes/api/webhooks.php).
                // rate.view seeds to every role — the till quotes it.
                Route::get('rate', [RateController::class, 'show'])
                    ->middleware('merchant.can:rate.view');

                // Feeds the split-by-category editor. Gated exactly as the
                // web mount (routes/api/product-categories.php):
                // product_categories.view seeds to every role — the list
                // feeds the credit form, and posting credits is till work.
                Route::get('product-categories', [ProductCategoriesController::class, 'index'])
                    ->middleware('merchant.can:product_categories.view');

                /*
                 * The settlements suite (merchant app MR3) — the web mount
                 * (routes/api/settlements.php) verbatim: same controller, same
                 * domain services (OutstandingSummary, SettlementPreview,
                 * SettlementBuilder), same permission slugs in the same order.
                 * Receipt-first (PLAN §1): the app previews what a selection
                 * costs and where to send it, the merchant transfers at their
                 * bank, and SUBMITTING the slip is the single act that creates
                 * a settlement — multipart, because the slip travels with the
                 * claim, and what that file really IS is decided by SlipStorage
                 * reading its magic bytes, not by anything this layer trusts.
                 *
                 * The three writes take the DEFAULT EnsureMerchantApproved, not
                 * the `:trading` strength the till void above uses — and that
                 * difference is the point. Submitting a settlement freezes
                 * lines irreversibly and claims a real bank transfer, so it
                 * belongs to a store that has actually passed review (draft /
                 * pending_review / rejected → 409 store_not_approved, in the
                 * envelope). But a SUSPENDED or closed store is deliberately
                 * admitted: suspension is the platform's only credit control
                 * (§7, day 16 of non-payment) and settling is the one act that
                 * ends it — a gate that refused a suspended shopkeeper's money
                 * would make the suspension permanent. Suspension is never a
                 * locked panel.
                 *
                 * Permission FIRST on every route, as everywhere on this
                 * surface: an account that may not touch money is refused
                 * before the response can reveal the store's standing.
                 * `outstanding` answers to settlements.view — it is the
                 * unsettled-lines read model the builder assembles from, not a
                 * separate surface — while the wallet is its own pair, because
                 * spending a wallet balance settles real money without a bank
                 * transfer ever being made. Every {id} resolves through the
                 * authenticated merchant's own relations: another merchant's
                 * settlement is indistinguishable from a missing one.
                 */
                Route::get('outstanding', [SettlementController::class, 'outstanding'])
                    ->middleware('merchant.can:settlements.view');

                Route::get('wallet', [SettlementController::class, 'wallet'])
                    ->middleware('merchant.can:wallet.view');

                Route::get('settlements', [SettlementController::class, 'index'])
                    ->middleware('merchant.can:settlements.view');

                Route::get('settlements/preview', [SettlementController::class, 'preview'])
                    ->middleware('merchant.can:settlements.preview');

                Route::get('settlements/{id}', [SettlementController::class, 'show'])
                    ->whereNumber('id')
                    ->middleware('merchant.can:settlements.view');

                Route::post('settlements', [SettlementController::class, 'store'])
                    ->middleware(['merchant.can:settlements.create', EnsureMerchantApproved::class]);

                Route::post('settlements/wallet', [SettlementController::class, 'walletSettle'])
                    ->middleware(['merchant.can:wallet.settle', EnsureMerchantApproved::class]);

                // Funding the wallet by bank transfer (owner, 2026-08-24) —
                // the EXACT web route (routes/api/settlements.php), same
                // controller, same gate: multipart account + slip +
                // optional reference, creating a pending claim.
                Route::post('wallet/top-ups', [WalletTopUpController::class, 'store'])
                    ->middleware(['merchant.can:wallet.top_up', EnsureMerchantApproved::class, 'throttle:5,1']);

                // A further transfer against a batch still owed money (§7
                // partial payments, or an admin-built fallback batch) — also
                // receipt-bearing, also multipart.
                Route::post('settlements/{id}/receipts', [SettlementController::class, 'storeReceipt'])
                    ->whereNumber('id')
                    ->middleware(['merchant.can:settlements.receipt_add', EnsureMerchantApproved::class]);

                Route::get('devices', [MerchantDevicesController::class, 'index']);
                Route::delete('devices/{id}', [MerchantDevicesController::class, 'destroy'])->whereNumber('id');
                Route::delete('devices', [MerchantDevicesController::class, 'destroyAll']);

                Route::put('push-token', [MerchantPushTokenController::class, 'update']);
                Route::delete('push-token', [MerchantPushTokenController::class, 'destroy']);

                /*
                 * The setup wizard (merchant app MR1) — the EXACT web routes
                 * (routes/api/merchant-signup.php), same SetupController,
                 * same permission gates: EnsureMobileToken has set the
                 * merchant guard user, so merchant.can:* and the domain
                 * layer's draft/rejected write gate (409 setup_not_editable,
                 * enveloped) behave as on web. Payload shapes are the web's,
                 * unchanged — the wizard state object with categories,
                 * rate_bounds, missing[] and the rest.
                 *
                 * The write throttles are NAMED (mobile-setup-writes), not
                 * inline `throttle:20,1` — see the credits route above for
                 * the cross-audience id-collision the inline form invites.
                 */
                Route::get('setup', [SetupController::class, 'show'])
                    ->middleware('merchant.can:setup.view');

                Route::patch('setup/profile', [SetupController::class, 'updateProfile'])
                    ->middleware('merchant.can:setup.edit');

                Route::patch('setup/location', [SetupController::class, 'updateLocation'])
                    ->middleware('merchant.can:setup.edit');

                Route::post('setup/logo', [SetupController::class, 'storeLogo'])
                    ->middleware(['merchant.can:branding.update', 'throttle:mobile-setup-writes']);

                Route::patch('setup/rate', [SetupController::class, 'updateRate'])
                    ->middleware('merchant.can:setup.edit');

                Route::post('setup/submit', [SetupController::class, 'submit'])
                    ->middleware(['merchant.can:setup.submit', 'throttle:mobile-setup-writes']);

                /*
                 * The settings estate (merchant app MR5) — the web settings
                 * module (routes/api/merchant-settings.php), the
                 * product-category writes (routes/api/product-categories.php),
                 * the promotion lifecycle (routes/api/promotions.php) and the
                 * standing-rate change (routes/api/webhooks.php), all on the
                 * mobile surface: same controllers, same permission slugs, and
                 * the permission gate FIRST on every route, as everywhere on
                 * this tree — an account that may not act must be refused
                 * before the response can reveal the store's standing.
                 *
                 * The approval gates copy the web mounts exactly, because
                 * their DIFFERENCES are deliberate, in three strengths:
                 *
                 *  - the profile PATCH, the branch writes and the staff invite
                 *    take the DEFAULT EnsureMerchantApproved: pre-approval the
                 *    setup wizard is the sole write path, and a pending_review
                 *    store must not rewrite the fields — or grow the estate —
                 *    the superadmin queue is reviewing. Suspended stores pass:
                 *    suspension is credit control, not a locked panel.
                 *  - the bank account, staff edits, role writes and
                 *    preferences carry NO approval gate at all: a role mints
                 *    no account and shows the queue nothing, and deactivating
                 *    the cashier whose phone was taken is exactly what a
                 *    suspended — or still-waiting — store must stay able to do.
                 *  - the COMMERCIAL OFFER — the rate change, the per-category
                 *    rate card, promotion create/publish — takes the stricter
                 *    `:trading`: a suspended store creates no cashback, so an
                 *    offer set now would only spring live on reinstatement
                 *    (§7; see EnsureMerchantApproved's docblock). Cancel stays
                 *    open so no store is ever stranded with a draft.
                 *
                 * The logo upload is deliberately NOT re-mounted: the MR1
                 * setup route above (POST setup/logo) already serves the
                 * post-approval case — OnboardingService::LOGO_STATUSES
                 * admits draft, rejected AND active stores, which is exactly
                 * how the web panel changes a live store's logo too.
                 */
                Route::get('profile', [ProfileController::class, 'show'])
                    ->middleware('merchant.can:profile.view');

                // profile.edit seeds to the Owner alone — the store's public
                // identity. The owner support-phone convention (Merchant::
                // booted()) rides with the model, not this surface: a blank
                // support_phone materialises as the contact number, and "same
                // as contact" is a COMPARISON the app makes, sending the
                // contact value itself.
                Route::patch('profile', [ProfileController::class, 'update'])
                    ->middleware(['merchant.can:profile.edit', EnsureMerchantApproved::class]);

                // Pin → written address (web parity, same throttle).
                Route::get('branches/reverse-geocode', ReverseGeocodeController::class)
                    ->middleware(['merchant.can:branches.view', 'throttle:60,1']);

                Route::get('branches', [BranchesController::class, 'index'])
                    ->middleware('merchant.can:branches.view');

                Route::post('branches', [BranchesController::class, 'store'])
                    ->middleware(['merchant.can:branches.create', EnsureMerchantApproved::class]);

                Route::patch('branches/{id}', [BranchesController::class, 'update'])
                    ->whereNumber('id')
                    ->middleware(['merchant.can:branches.edit', EnsureMerchantApproved::class]);

                // DELETE is hard only while nothing references the branch —
                // a branch with history answers 409 branch_referenced, in the
                // envelope, and the app's soft alternative is to stop using it.
                Route::delete('branches/{id}', [BranchesController::class, 'destroy'])
                    ->whereNumber('id')
                    ->middleware(['merchant.can:branches.delete', EnsureMerchantApproved::class]);

                // Seeing the payout destination and repointing it are
                // different permissions (D6): repointing is the single most
                // consequential change on this screen.
                Route::get('bank-account', [BankAccountController::class, 'show'])
                    ->middleware('merchant.can:bank_account.view');

                Route::patch('bank-account', [BankAccountController::class, 'update'])
                    ->middleware('merchant.can:bank_account.update');

                Route::get('staff', [StaffController::class, 'index'])
                    ->middleware('merchant.can:staff.view');

                // The temp password is in THIS response and never again —
                // the app's one-time reveal sheet is not a nicety, it is the
                // only copy that will ever exist.
                Route::post('staff', [StaffController::class, 'store'])
                    ->middleware(['merchant.can:staff.invite', EnsureMerchantApproved::class]);

                Route::patch('staff/{id}', [StaffController::class, 'update'])
                    ->whereNumber('id')
                    ->middleware('merchant.can:staff.edit');

                // MR8 password reset — same slug as the PATCH (an operation
                // on an existing account, not a mint) and no approval gate,
                // mirroring the web mount exactly. The temp password is in
                // THIS response and never again; the target's mobile tokens
                // die with the old password, so their app signs out.
                Route::post('staff/{id}/reset-password', [StaffController::class, 'resetPassword'])
                    ->whereNumber('id')
                    ->middleware('merchant.can:staff.edit');

                // The permission CATALOGUE, published so the roles screen
                // renders groups and wording from the server (D8) — an app
                // build predating a permission still shows its checkbox.
                // Gated with the roles list itself: it is that screen's
                // other half.
                Route::get('permissions', [RolesController::class, 'permissions'])
                    ->middleware('merchant.can:roles.view');

                Route::get('roles', [RolesController::class, 'index'])
                    ->middleware('merchant.can:roles.view');

                // The delegation rules (D5: only what you hold, owner role
                // frozen, in-use delete refused) live in RoleService and
                // leave as coded refusals through the envelope — the app
                // points at the checkbox, never re-derives the rule.
                Route::post('roles', [RolesController::class, 'store'])
                    ->middleware('merchant.can:roles.manage');

                Route::patch('roles/{id}', [RolesController::class, 'update'])
                    ->whereNumber('id')
                    ->middleware('merchant.can:roles.manage');

                Route::delete('roles/{id}', [RolesController::class, 'destroy'])
                    ->whereNumber('id')
                    ->middleware('merchant.can:roles.manage');

                // Both knobs bound server-side (min eligible sale 0–100000
                // laari; the validation window capped at the ADMIN-governed
                // platform ceiling) and both apply to FUTURE credits only —
                // terms freeze onto each transaction at occurred_at (§4).
                Route::patch('preferences', [PreferencesController::class, 'update'])
                    ->middleware('merchant.can:preferences.update');

                // The writes behind the GET the till already has (MR2 above):
                // creating and repricing categories reprices future sales, so
                // they join the rate and promotions behind `:trading`.
                Route::post('product-categories', [ProductCategoriesController::class, 'store'])
                    ->middleware(['merchant.can:product_categories.create', EnsureMerchantApproved::class.':trading']);

                Route::patch('product-categories/{id}', [ProductCategoriesController::class, 'update'])
                    ->whereNumber('id')
                    ->middleware(['merchant.can:product_categories.edit', EnsureMerchantApproved::class.':trading']);

                // Only what the domain offers: list, draft, publish, cancel
                // DRAFT. No update and no early end — a published promotion
                // is immutable for its stated duration (§7), and publish is
                // its own permission because a draft costs nothing while
                // publishing is the irreversible act.
                Route::get('promotions', [PromotionController::class, 'index'])
                    ->middleware('merchant.can:promotions.view');

                Route::post('promotions', [PromotionController::class, 'store'])
                    ->middleware(['merchant.can:promotions.create', EnsureMerchantApproved::class.':trading']);

                Route::post('promotions/{id}/publish', [PromotionController::class, 'publish'])
                    ->whereNumber('id')
                    ->middleware(['merchant.can:promotions.publish', EnsureMerchantApproved::class.':trading']);

                Route::post('promotions/{id}/cancel', [PromotionController::class, 'cancel'])
                    ->whereNumber('id')
                    ->middleware('merchant.can:promotions.cancel');

                // The other half of the MR2 rate GET above: the §7 change
                // semantics live in the controller (increase now, decrease at
                // next business midnight, a new change replacing any pending
                // one) and the response carries the §4 tier-cliff data the
                // app must warn with.
                Route::post('rate', [RateController::class, 'store'])
                    ->middleware(['merchant.can:rate.update', EnsureMerchantApproved::class.':trading']);
            });
    });
