<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('references only defined auth guards from sanctum', function () {
    // Sanctum iterates sanctum.guard and calls Auth::guard() for each; an
    // undefined name (the removed default 'web') would 500 every
    // auth:sanctum request before token resolution.
    $defined = array_keys(config('auth.guards'));
    $sanctumGuards = (array) config('sanctum.guard');

    expect($sanctumGuards)->not->toBeEmpty();

    foreach ($sanctumGuards as $guard) {
        expect($defined)->toContain($guard);
    }
});
