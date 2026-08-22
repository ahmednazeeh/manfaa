<?php

use App\Http\Controllers\Admin\BrandAssetsController;
use App\Http\Controllers\BrandAssetController;
use App\Http\Middleware\EnsureSuperadmin;
use Illuminate\Support\Facades\Route;

/*
 * The platform's brand marks.
 *
 * Reading is PUBLIC and unauthenticated — these are the logos on the login
 * pages, so requiring a session to fetch one would be circular. The route
 * always answers an image (the packaged default when nothing is uploaded),
 * which is what lets every frontend point an <img> at it with no fallback.
 */
Route::get('brand/{slot}', BrandAssetController::class)
    ->where('slot', '[a-z_]+');

/*
 * Replacing one is superadmin-only: these five files are the platform's face
 * on every public surface.
 */
Route::prefix('admin/brand')
    ->middleware(['auth:admin', EnsureSuperadmin::class])
    ->group(function (): void {
        Route::get('/', [BrandAssetsController::class, 'index']);
        Route::post('{slot}', [BrandAssetsController::class, 'store'])
            ->where('slot', '[a-z_]+')
            ->middleware('throttle:30,1');
        Route::delete('{slot}', [BrandAssetsController::class, 'destroy'])
            ->where('slot', '[a-z_]+');
    });
