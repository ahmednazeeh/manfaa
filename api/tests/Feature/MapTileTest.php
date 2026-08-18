<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('serves a tile from our own origin and caches it for the next caller', function () {
    Storage::fake('local');
    Http::fake([
        'tile.openstreetmap.org/*' => Http::response('PNGBYTES', 200),
    ]);

    $this->get('/api/map/tiles/12/2560/1800.png')
        ->assertOk()
        ->assertHeader('content-type', 'image/png');

    Storage::disk('local')->assertExists('map-tiles/12/2560/1800.png');

    // The second caller must not reach OSM at all.
    Http::fake(['tile.openstreetmap.org/*' => Http::response('SHOULD NOT BE CALLED', 500)]);
    $this->get('/api/map/tiles/12/2560/1800.png')->assertOk();
});

it('identifies itself to OSM, as the tile policy requires', function () {
    Storage::fake('local');
    Http::fake(['tile.openstreetmap.org/*' => Http::response('PNGBYTES', 200)]);

    $this->get('/api/map/tiles/5/10/10.png')->assertOk();

    Http::assertSent(fn ($request) => str_contains(
        $request->header('User-Agent')[0] ?? '',
        'ManfaaMaps',
    ));
});

it('refuses coordinates outside the pyramid rather than walking the planet', function () {
    Storage::fake('local');
    Http::fake();

    $this->get('/api/map/tiles/99/1/1.png')->assertNotFound();
    $this->get('/api/map/tiles/2/99/1.png')->assertNotFound();
    $this->get('/api/map/tiles/2/1/99.png')->assertNotFound();

    Http::assertNothingSent();
});

it('serves a stale tile rather than a grey square when OSM is down', function () {
    Storage::fake('local');
    Storage::disk('local')->put('map-tiles/9/300/200.png', 'OLDBYTES');
    Http::fake(['tile.openstreetmap.org/*' => Http::response('boom', 503)]);

    // Age it past the refresh window so the fetch is attempted.
    touch(Storage::disk('local')->path('map-tiles/9/300/200.png'), now()->subDays(40)->getTimestamp());

    $response = $this->get('/api/map/tiles/9/300/200.png')->assertOk();
    expect($response->getContent())->toBe('OLDBYTES');
});
