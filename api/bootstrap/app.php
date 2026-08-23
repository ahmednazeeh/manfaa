<?php

use App\Http\Middleware\EnsureMarketplaceEnabled;
use App\Http\Middleware\EnsureMerchantPermission;
use App\Http\Middleware\EnsureMerchantRole;
use App\Http\Middleware\EnsureMobileToken;
use App\Http\Middleware\ThrottlePerRoute;
use App\Http\Responses\MobileError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum stateful SPA auth: first-party panels and the customer web
        // app authenticate over session cookies against the api routes.
        $middleware->statefulApi();

        // The self-referral defence's browser ref (DeviceIdentity::WEB_COOKIE)
        // stays UNencrypted on purpose: EncryptCookies only runs on the
        // stateful branch of these api routes, and an encrypted value would
        // arrive as ciphertext on the stateless branch and never match its
        // UUID-v4 shape. It is a random ref, not a secret — a visitor could
        // clear or forge it anyway, so the MAC bought nothing.
        $middleware->encryptCookies(except: ['mfa_did']);

        // The merchant panel's permission gate, parameterised by the one
        // permission a route needs: merchant.can:settlements.create (PLAN
        // §13b staff permissions).
        //
        // `merchant.role` stays registered on purpose. It is retired, and
        // EnsureMerchantRole now throws — but an alias Laravel cannot
        // resolve is a worse failure than one that refuses loudly, and a
        // route file the refactor missed must never end up ungated.
        $middleware->alias([
            'merchant.can' => EnsureMerchantPermission::class,
            'merchant.role' => EnsureMerchantRole::class,

            // The mobile API's token-type gate, parameterised by audience:
            // mobile.token:customer / mobile.token:merchant. Sanctum resolves
            // a bearer token to ANY tokenable that uses HasApiTokens — all
            // four models do — so this is what proves which app is calling.
            // See EnsureMobileToken; no /api/mobile route may skip it.
            'mobile.token' => EnsureMobileToken::class,

            // The marketplace kill switch. Every marketplace route wears it,
            // so "hidden" is enforced by the server rather than trusted to
            // four clients (PLAN-marketplace.md §10).
            'marketplace' => EnsureMarketplaceEnabled::class,

            // Inline throttle:n,1 with a bucket per ROUTE, not one shared
            // counter per caller. Named limiters are unaffected. See
            // App\Http\Middleware\ThrottlePerRoute.
            'throttle' => ThrottlePerRoute::class,
        ]);

        // Origin sits behind nginx (same host) with Cloudflare in front;
        // trust the proxy so X-Forwarded-Proto marks requests secure and
        // secure cookies survive the fastcgi hop.
        //
        // X-Forwarded-FOR is deliberately EXCLUDED from that trust. nginx
        // already resolves the true client address correctly — it accepts
        // real-ip only from the Cloudflare ranges and reads CF-Connecting-IP
        // (/etc/nginx/snippets/cloudflare-realip.conf) — so REMOTE_ADDR
        // reaching PHP is right. Trusting X-Forwarded-For threw that away:
        // Symfony treats every hop as trusted and returns the LEFTMOST
        // entry, which is whatever the caller typed. `$request->ip()` was
        // therefore attacker-controlled, and since ThrottleRequests keys
        // unauthenticated limits on exactly that, rotating one header bought
        // a fresh rate-limit bucket on every request to every login on the
        // platform. Dropping the header from the trust list makes ip() fall
        // back to nginx's REMOTE_ADDR, which a client cannot forge.
        //
        // Proto/host/port stay trusted, which is the whole reason the
        // original '*' was here.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        /*
         * Only OUR hosts may name this application (security audit
         * 2026-08-19).
         *
         * X-Forwarded-Host is trusted from any client above — that is what
         * makes the proxy work — which meant a request could declare itself
         * to be from anywhere, and every absolute URL built with url()
         * inherited it. The public storefront caches its answers for sixty
         * seconds, so one poisoned request served attacker-hosted logo and
         * image URLs to whoever asked next.
         *
         * An allowlist fixes it at the right layer: the header stays
         * trusted for proto and port, and a host we do not serve is refused
         * outright rather than reflected.
         */
        $middleware->trustHosts(at: [
            'manfaa.app',
            'www.manfaa.app',
            'api.manfaa.app',
            'admin.manfaa.app',
            'merchant.manfaa.app',
        ]);

        // API-only app: never redirect guests to a (nonexistent) login page —
        // an unauthenticated request without an Accept header would otherwise
        // 500 on route('login'). Null → AuthenticationException → JSON 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The mobile tree — and ONLY the mobile tree — answers in the
        // {error:{code,message,meta}} envelope (PLAN-mobile-api.md M2).
        //
        // Scoped by path on purpose. The panels deploy in lockstep with their
        // API and are happy with Laravel's stock shapes; the /v1 vendor
        // contract is published in docs/openapi.yaml and POS vendors parse it
        // today. Neither may change shape because the apps wanted one parser.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/mobile/*')) {
                return null;
            }

            return MobileError::fromException($e);
        });
    })->create();
