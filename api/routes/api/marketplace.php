<?php

use App\Http\Controllers\Admin\MarketplaceKybController;
use App\Http\Controllers\Merchant\MarketplaceEnrolmentController;
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

Route::prefix('admin/marketplace')
    ->middleware(['auth:admin', 'marketplace'])
    ->group(function (): void {
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
