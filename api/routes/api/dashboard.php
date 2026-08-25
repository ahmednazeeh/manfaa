<?php

use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

// The admin console's landing page (owner, 2026-08-25): ONE endpoint holding
// everything a dashboard shows, so it never fans out into a dozen parallel
// reads whose answers come from a dozen different instants.
//
// auth:admin only — no EnsureSuperadmin on the route. The money panel and
// the daily chart are superadmin-only and the controller omits them for a
// plain admin, exactly as the Reports page is gated; everything else on the
// page (what is waiting for a human, whether the bank matcher is alive, who
// signed up) is ordinary admin work and a whole-route gate would lock the
// operational half of the console away with the financial half.
Route::prefix('admin')->middleware('auth:admin')->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'show']);

    // The attention counts on their own, for the console's nav badges: the
    // same six predicates, no period, and no list rows fetched to reach a
    // scalar. See DashboardController::attention.
    Route::get('dashboard/attention', [DashboardController::class, 'attention']);
});
