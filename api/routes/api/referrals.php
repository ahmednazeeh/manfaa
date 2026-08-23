<?php

use App\Http\Controllers\Customer\ReferralsController;
use Illuminate\Support\Facades\Route;

/*
 * The customer referral programme (owner, 2026-08-23). One read: the
 * customer's code, the programme's live figures, and how each invited
 * friend is progressing. The mobile tree mounts the SAME controller —
 * routes/api/mobile.php, beside the wallet.
 *
 * The three programme settings are ordinary platform settings, managed
 * through the generic admin GET/PATCH in routes/api/platform.php.
 */
Route::prefix('customer')->middleware('auth:customer')->group(function (): void {
    Route::get('referrals', [ReferralsController::class, 'show']);
});
