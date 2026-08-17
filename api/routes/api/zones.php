<?php

use App\Http\Controllers\Admin\ZonesController;
use Illuminate\Support\Facades\Route;

// Island zoning — admin-drawn polygons that group branches by island. The
// customer-facing zone list lives with the other public discovery routes in
// customer.php; this file is the admin's drawing surface.
Route::prefix('admin')->middleware('auth:admin')->group(function () {
    Route::get('zones', [ZonesController::class, 'index']);
    Route::post('zones', [ZonesController::class, 'store']);
    // Declared before {zone} and with the number constraint below so the
    // literal "order" can never be captured as a zone id.
    Route::put('zones/order', [ZonesController::class, 'reorder']);
    Route::put('zones/{zone}', [ZonesController::class, 'update'])->whereNumber('zone');
    Route::delete('zones/{zone}', [ZonesController::class, 'destroy'])->whereNumber('zone');
});
