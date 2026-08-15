<?php

use App\Http\Controllers\MerchantLogoController;
use App\Http\Controllers\StoreCategoryIconController;
use Illuminate\Support\Facades\Route;

// Store logos. Not under /discover: the same URL answers the public
// storefront AND the merchant's own pre-approval panel, and the controller
// — not the route — decides which, from the store's status.
//
// No auth middleware: the route is reachable anonymously and the handler
// authorises. Sanctum's stateful middleware (bootstrap/app.php
// statefulApi()) has already hydrated the session guards by then, so
// `$request->user('merchant' | 'admin')` resolves for a logged-in panel
// without forcing every anonymous storefront image through an auth wall.
//
// Throttle: generous and per-IP. One storefront page paints up to 24 cards,
// each an image request from the visitor's own address, so the 60/min
// `discovery` limiter would break the very page it protects. The response
// is immutable-cacheable (content-versioned URL) — a real browser fetches
// each logo once.
Route::get('merchants/{slug}/logo', MerchantLogoController::class)
    ->where('slug', '[a-z0-9-]{1,80}')
    ->middleware('throttle:240,1');

// Curated store-category icons — the rail's artwork. Same reasoning as the
// logos above, minus the authorisation: a category is public unconditionally,
// so the handler has nothing to decide. The rail paints one image per
// category on the landing page, hence the same generous per-IP throttle.
Route::get('store-categories/{slug}/icon', StoreCategoryIconController::class)
    ->where('slug', '[a-z0-9-]{1,80}')
    ->middleware('throttle:240,1');
