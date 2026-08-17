<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/**
 * `trustProxies` is platform-wide, so its two halves are pinned here.
 *
 * The application must trust the proxy for SCHEME (or every URL it generates
 * goes out http:// and secure cookies stop surviving the fastcgi hop) while
 * NOT trusting it for the client ADDRESS (or `$request->ip()` becomes
 * whatever the caller typed, and every unauthenticated rate limit on the
 * platform — all of which key on it — can be rotated around by changing one
 * header).
 *
 * Getting exactly one of these right is easy and silently wrong either way.
 */
beforeEach(function () {
    Route::get('/_test/proxy', fn (Request $request) => [
        'ip' => $request->ip(),
        'secure' => $request->isSecure(),
    ]);
});

it('ignores a client-supplied X-Forwarded-For when resolving the client address', function () {
    // nginx already resolves the true address — it accepts real-ip only from
    // the Cloudflare ranges and reads CF-Connecting-IP — so REMOTE_ADDR is
    // right and the forwarded header is only ever noise or an attack.
    $this->get('/_test/proxy', ['X-Forwarded-For' => '203.0.113.99'])
        ->assertOk()
        ->assertJsonPath('ip', '127.0.0.1');
});

it('cannot be walked around with a spoofed forwarding chain', function () {
    $this->get('/_test/proxy', ['X-Forwarded-For' => '198.51.100.7, 203.0.113.99, 10.0.0.1'])
        ->assertOk()
        ->assertJsonPath('ip', '127.0.0.1');
});

it('still trusts the proxy for the scheme, so secure cookies survive the fastcgi hop', function () {
    $this->get('/_test/proxy', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertJsonPath('secure', true);
});

it('leaves a plain request insecure', function () {
    $this->get('/_test/proxy')
        ->assertOk()
        ->assertJsonPath('secure', false);
});
